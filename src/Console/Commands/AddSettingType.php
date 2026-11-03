<?php

namespace Coyote6\LaravelSettings\Console\Commands;

use Coyote6\LaravelSettings\Concerns\Console\WritesSettingFiles;
use Coyote6\LaravelSettings\Concerns\HasSettings;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\suggest;
use function Laravel\Prompts\text;

/**
 * Create a settings type: the migration, enum and model for one model's settings, plus its config entry.
 *
 * @phpstan-type NewType array{
 *     key: string,
 *     owner: class-string<Model>,
 *     enum: string,
 *     model: string,
 *     table: string,
 *     ownerKey: string,
 *     cases: list<array{case: string, value: string, label: string, default: string}>
 * }
 */
class AddSettingType extends Command implements PromptsForMissingInput
{
    use WritesSettingFiles;

    /**
     * The comment in config/settings.php that new types are added below.
     */
    public const CONFIG_MARKER = '// settings:add-type adds entries below this line.';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:add-type
        {model : The model that owns these settings, e.g. Client}
        {--enum= : The enum that names the settings, e.g. App\Enums\Settings\ClientSetting}
        {--names= : The setting names to start with, comma-separated, e.g. theme,text-alerts}
        {--table= : The settings table, e.g. client_settings}
        {--owner-key= : The column in that table holding the owner key, e.g. client_id}
        {--migrate : Run the new migration without asking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a settings table, enum and model for one model, and register it in config/settings.php';

    /**
     * Execute the console command.
     *
     * Every answer is checked before anything is written, so a refused run
     * leaves no files behind.
     *
     * @label Handle
     *
     * @param  Filesystem  $files  The filesystem used to find and write files
     */
    public function handle(Filesystem $files): int
    {
        try {
            $type = $this->collectType();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $problems = $this->problems($files, $type);

        if ($problems !== []) {
            $this->components->error('Nothing was created:');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $migration = $this->writeMigration($files, $type);
        $this->writeEnum($files, $type);
        $this->writeModel($files, $type);
        $this->registerType($files, $type);

        $this->callSilently('config:clear');

        if ($this->shouldMigrate('Run this migration now?')) {
            $this->runMigration($migration);
        }

        $this->printNextSteps($type);

        return self::SUCCESS;
    }

    /**
     * Get the question to ask when the model argument is missing.
     *
     * @label Prompt For Missing Arguments Using
     *
     * @return array<string, callable(): string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'model' => fn () => suggest(
                label: 'Which model are these settings for?',
                options: $this->availableModels(),
                placeholder: 'E.g. Client',
                required: true,
            ),
        ];
    }

    /**
     * Collect and check every answer: from the options, else by asking, else the defaults.
     *
     * @label Collect Type
     *
     * @return NewType
     *
     * @throws InvalidArgumentException When an answer cannot be used
     */
    protected function collectType(): array
    {
        $model = $this->argument('model');

        if (! is_string($model)) {
            throw new InvalidArgumentException('Give the model these settings are for, e.g. Client.');
        }

        $owner = $this->ownerClass($model);
        $basename = class_basename($owner);
        $key = Str::snake($basename);
        $namespace = $this->laravel->getNamespace();

        $this->ensureNoTypeFor($owner, $key);

        $enum = ltrim($this->answer('enum', 'Which enum names these settings?', "{$namespace}Enums\\Settings\\{$basename}Setting"), '\\');
        $cases = $this->parseNames($this->names());
        $table = $this->answer('table', 'What should the settings table be called?', Str::snake($basename).'_settings');

        $this->ensureIdentifier($enum, '/^[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)*$/', 'enum class');
        $this->ensureIdentifier($table, '/^\w{1,64}$/', 'table name');

        $ownerKey = $this->ownerKey($owner, $table);

        $this->ensureIdentifier($ownerKey, '/^\w{1,64}$/', 'owner column');

        return [
            'key' => $key,
            'owner' => $owner,
            'enum' => $enum,
            'model' => "{$namespace}Models\\Settings\\{$basename}SettingValue",
            'table' => $table,
            'ownerKey' => $ownerKey,
            'cases' => $cases,
        ];
    }

    /**
     * Get the owner model's full class name from what was typed.
     *
     * @label Owner Class
     *
     * @ai Short names are qualified the way Laravel's make: commands do
     *     (GeneratorCommand::qualifyModel()): App\Models\Client when
     *     app/Models exists, else App\Client. A name that is already a
     *     class is used as typed, so models outside App\ work too.
     *
     * @param  string  $model  The typed name, e.g. Client or App\Models\Client
     * @return class-string<Model>
     *
     * @throws InvalidArgumentException When it is not a model that can be created
     */
    protected function ownerClass(string $model): string
    {
        $model = str_replace('/', '\\', ltrim(trim($model), '\\/'));
        $namespace = $this->laravel->getNamespace();

        if (! class_exists($model) && ! Str::startsWith($model, $namespace)) {
            $model = (is_dir(app_path('Models')) ? "{$namespace}Models\\" : $namespace).$model;
        }

        if (! is_subclass_of($model, Model::class) || ! (new ReflectionClass($model))->isInstantiable()) {
            throw new InvalidArgumentException("[{$model}] is not an Eloquent model.");
        }

        return $model;
    }

    /**
     * Refuse a model that already has a settings type, or a type key already in use.
     *
     * @label Ensure No Type For
     *
     * @param  class-string<Model>  $owner  The owner model, e.g. App\Models\Client
     * @param  string  $key  The new type's key, e.g. client
     *
     * @throws InvalidArgumentException
     */
    protected function ensureNoTypeFor(string $owner, string $key): void
    {
        foreach (config()->array('settings.types', []) as $existing => $config) {
            $existingOwner = is_array($config) && is_string($config['owner'] ?? null) ? ltrim($config['owner'], '\\') : null;

            if ($existingOwner === $owner) {
                throw new InvalidArgumentException("[{$owner}] already has a settings type, [{$existing}], in config/settings.php. Each model can have one.");
            }

            if ($existing === $key) {
                throw new InvalidArgumentException("There is already a settings type named [{$key}] in config/settings.php.");
            }
        }
    }

    /**
     * Get an answer from its option, else by asking in a terminal, else the default.
     *
     * @label Answer
     *
     * @param  string  $option  The option that answers it, e.g. table
     * @param  string  $question  The question to ask, e.g. What should the settings table be called?
     * @param  string  $default  The default answer, e.g. client_settings
     */
    protected function answer(string $option, string $question, string $default): string
    {
        $given = $this->option($option);

        if (is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        if (! $this->input->isInteractive()) {
            return $default;
        }

        return trim(text(label: $question, default: $default, required: true));
    }

    /**
     * Get the setting names to start with, as typed.
     *
     * @label Names
     *
     * @throws InvalidArgumentException When none were given without a terminal
     */
    protected function names(): string
    {
        $given = $this->option('names');

        if (is_string($given) && trim($given) !== '') {
            return $given;
        }

        if (! $this->input->isInteractive()) {
            throw new InvalidArgumentException('Give the setting names to start with, e.g. --names=theme,text-alerts.');
        }

        return text(
            label: 'Which settings should the enum start with?',
            placeholder: 'theme, text-alerts',
            required: true,
            hint: 'Comma-separated. You can add more cases to the enum later.',
        );
    }

    /**
     * Turn the typed setting names into enum cases.
     *
     * @label Parse Names
     *
     * @ai A capital after a lowercase letter or digit starts a new word, so
     *     textAlerts and "Text Alerts" both become text-alerts. Runs of
     *     capitals stay one word: "SMS alerts" becomes sms-alerts, not
     *     s-m-s-alerts as Str::kebab() would make it.
     *
     * @param  string  $names  Comma-separated names, e.g. theme, Text Alerts, show_fielding_stats
     * @return list<array{case: string, value: string, label: string, default: string}>
     *
     * @throws InvalidArgumentException When a name cannot be an enum case, or appears twice
     */
    protected function parseNames(string $names): array
    {
        $cases = [];

        foreach (explode(',', $names) as $name) {
            $value = Str::slug((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', trim($name)));

            if ($value === '') {
                continue;
            }

            $case = Str::studly($value);

            if (preg_match('/^[A-Za-z_]\w*$/', $case) !== 1) {
                throw new InvalidArgumentException("[{$name}] cannot be an enum case: it must start with a letter.");
            }

            if (strlen($value) > 100) {
                throw new InvalidArgumentException("[{$name}] is longer than the 100 characters the name column holds.");
            }

            if (isset($cases[$case])) {
                throw new InvalidArgumentException("[{$name}] is the same setting as [{$cases[$case]['value']}].");
            }

            $cases[$case] = ['case' => $case, 'value' => $value, 'label' => Str::headline($value), 'default' => 'null'];
        }

        if ($cases === []) {
            throw new InvalidArgumentException('Give at least one setting name, e.g. --names=theme,text-alerts.');
        }

        return array_values($cases);
    }

    /**
     * Get the settings table's owner column, confirming the detected one in a terminal.
     *
     * @label Owner Key
     *
     * @param  class-string<Model>  $owner  The owner model, e.g. App\Models\Client
     * @param  string  $table  The settings table, e.g. client_settings
     */
    protected function ownerKey(string $owner, string $table): string
    {
        $given = $this->option('owner-key');

        if (is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        $model = new $owner;
        $detected = $model->getForeignKey();

        if (! $this->input->isInteractive()) {
            return $detected;
        }

        $question = sprintf(
            '%s.%s → %s.%s (%s). Correct?',
            $table,
            $detected,
            $model->getTable(),
            $model->getKeyName(),
            $this->keyType($model),
        );

        if (confirm($question, true)) {
            return $detected;
        }

        return trim(text(label: "Which column of {$table} holds the owner key?", default: $detected, required: true));
    }

    /**
     * Check a name against a pattern.
     *
     * @label Ensure Identifier
     *
     * @param  string  $value  The name, e.g. client_settings
     * @param  string  $pattern  The regular expression it must match
     * @param  string  $what  What the name is, for the message, e.g. table name
     *
     * @throws InvalidArgumentException
     */
    protected function ensureIdentifier(string $value, string $pattern, string $what): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a valid {$what}.");
        }
    }

    /**
     * List everything that stops the type from being created.
     *
     * @label Problems
     *
     * @param  Filesystem  $files  The filesystem used to look for existing files
     * @param  NewType  $type  The new type
     * @return list<string>
     */
    protected function problems(Filesystem $files, array $type): array
    {
        $problems = [];

        foreach (['enum' => $type['enum'], 'model' => $type['model']] as $what => $class) {
            $path = $this->appClassPath($class);

            if ($path === null) {
                $problems[] = "The {$what} [{$class}] is outside the [{$this->laravel->getNamespace()}] namespace.";
            } elseif ($files->exists($path)) {
                $problems[] = "The {$what} [{$class}] already exists.";
            }
        }

        if ($this->tableExists($type['table'])) {
            $problems[] = "A [{$type['table']}] table already exists.";
        }

        foreach ($this->existingMigrations($files, $type['table']) as $migration) {
            $problems[] = "A migration for [{$type['table']}] already exists: {$this->relativePath($migration)}";
        }

        return $problems;
    }

    /**
     * Write the settings table migration and return its path.
     *
     * @label Write Migration
     *
     * @param  Filesystem  $files  The filesystem used to write the migration
     * @param  NewType  $type  The new type
     */
    protected function writeMigration(Filesystem $files, array $type): string
    {
        $path = database_path("migrations/{$this->migrationPrefix($files)}_create_{$type['table']}_table.php");

        $this->writeFile($files, $path, $this->renderMigration($files, $type));

        return $path;
    }

    /**
     * Build the migration's source from the migration stub.
     *
     * @label Render Migration
     *
     * @ai foreignIdFor() picks the column type from the owner model
     *     (Blueprint::foreignIdFor(): bigint for int keys, ulid for HasUlids,
     *     otherwise uuid), so it is used for every owner except one with a
     *     plain string key, which foreignIdFor() would wrongly make a uuid
     *     column. That one gets a string column and an explicit foreign key.
     *     MySQL and MariaDB allow a foreign key between string columns of
     *     different lengths; the run against MariaDB in CI checks this.
     *
     * @param  Filesystem  $files  The filesystem used to read the stub
     * @param  NewType  $type  The new type
     */
    protected function renderMigration(Filesystem $files, array $type): string
    {
        $owner = new $type['owner'];
        $imports = [
            'Illuminate\Database\Migrations\Migration',
            'Illuminate\Database\Schema\Blueprint',
            'Illuminate\Support\Facades\Schema',
        ];

        if ($this->keyType($owner) === 'string') {
            $column = [
                "\$table->string('{$type['ownerKey']}');",
                "\$table->foreign('{$type['ownerKey']}')->references('{$owner->getKeyName()}')->on('{$owner->getTable()}')->cascadeOnDelete();",
            ];
        } else {
            $short = class_basename($type['owner']);

            // Keep the stub's own imports unambiguous.
            if (in_array($short, ['Migration', 'Blueprint', 'Schema'], true)) {
                $reference = '\\'.$type['owner'];
            } else {
                $reference = $short;
                $imports[] = $type['owner'];
            }

            $name = $type['ownerKey'] === $owner->getForeignKey() ? '' : ", '{$type['ownerKey']}'";
            $column = ["\$table->foreignIdFor({$reference}::class{$name})->constrained()->cascadeOnDelete();"];
        }

        return str_replace(
            ['{{ imports }}', '{{ table }}', '{{ owner }}', '{{ ownerColumn }}', '{{ ownerKey }}'],
            [
                $this->useStatements($imports),
                $type['table'],
                $this->ownerWords($type),
                implode("\n", array_map(fn (string $line) => "            {$line}", $column)),
                $type['ownerKey'],
            ],
            $files->get($this->stubPath('setting-migration.stub')),
        );
    }

    /**
     * Write the type's enum.
     *
     * @label Write Enum
     *
     * @param  Filesystem  $files  The filesystem used to write the enum
     * @param  NewType  $type  The new type
     */
    protected function writeEnum(Filesystem $files, array $type): void
    {
        $this->writeFile($files, (string) $this->appClassPath($type['enum']), $this->renderEnum(
            $files,
            $type['enum'],
            "The settings each {$this->ownerWords($type)} can change.",
            $type['cases'],
        ));
    }

    /**
     * Write the type's value model.
     *
     * @label Write Model
     *
     * @param  Filesystem  $files  The filesystem used to write the model
     * @param  NewType  $type  The new type
     */
    protected function writeModel(Filesystem $files, array $type): void
    {
        $this->writeFile($files, (string) $this->appClassPath($type['model']), str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ owner }}', '{{ table }}'],
            [Str::beforeLast($type['model'], '\\'), class_basename($type['model']), $this->ownerWords($type), $type['table']],
            $files->get($this->stubPath('setting-model.stub')),
        ));
    }

    /**
     * Add the type to config/settings.php, publishing the config first if needed.
     *
     * @label Register Type
     *
     * @ai The entry goes on the line below CONFIG_MARKER and its classes are
     *     imported at the top of the file, sorted, because that is what
     *     Pint's laravel preset leaves alone (fully_qualified_strict_types
     *     and ordered_imports rewrite full class names). Laravel's
     *     install:api edits bootstrap/app.php the same way, by finding a
     *     known line. Without the marker, the entry is printed to paste.
     *
     * @param  Filesystem  $files  The filesystem used to edit the config
     * @param  NewType  $type  The new type
     */
    protected function registerType(Filesystem $files, array $type): void
    {
        $path = config_path('settings.php');

        if (! $files->exists($path)) {
            $this->callSilently('vendor:publish', ['--tag' => 'settings-config']);
            $this->components->twoColumnDetail('config/settings.php', '<fg=green;options=bold>PUBLISHED</>');
        }

        $config = $files->get($path);

        if (preg_match('/^([ \t]*)'.preg_quote(self::CONFIG_MARKER, '/').'[^\n]*\n/m', $config, $marker, PREG_OFFSET_CAPTURE) !== 1) {
            $this->components->warn('config/settings.php has no "'.self::CONFIG_MARKER.'" line, so it was not changed. Add this to its "types":');
            $this->line($this->configEntry($type, '        ', [
                $type['owner'] => '\\'.$type['owner'],
                $type['enum'] => '\\'.$type['enum'],
                $type['model'] => '\\'.$type['model'],
            ]));

            return;
        }

        $insertAt = $marker[0][1] + strlen($marker[0][0]);
        $config = substr($config, 0, $insertAt).$this->configEntry($type, $marker[1][0], [])."\n".substr($config, $insertAt);

        [$config, $references] = $this->importClasses($config, [$type['owner'], $type['enum'], $type['model']]);

        $files->put($path, strtr($config, $references));

        $this->components->twoColumnDetail('config/settings.php', '<fg=green;options=bold>UPDATED</>');
    }

    /**
     * Build the type's config entry.
     *
     * @label Config Entry
     *
     * @param  NewType  $type  The new type
     * @param  string  $indent  The indentation of the entry's first line, e.g. eight spaces
     * @param  array<string, string>  $references  How to write each class; a class not listed is written as a placeholder that importClasses() replaces
     */
    protected function configEntry(array $type, string $indent, array $references): string
    {
        $class = fn (string $class): string => ($references[$class] ?? "{{ {$class} }}").'::class';

        $lines = [
            "'{$type['key']}' => [",
            "    'owner' => {$class($type['owner'])},",
            "    'enum' => {$class($type['enum'])},",
            "    'model' => {$class($type['model'])},",
        ];

        // Only a column other than the owner's foreign key name needs saying.
        if ($type['ownerKey'] !== (new $type['owner'])->getForeignKey()) {
            $lines[] = "    'owner_key' => '{$type['ownerKey']}',";
        }

        $lines[] = '],';

        return implode("\n", array_map(fn (string $line) => $indent.$line, $lines));
    }

    /**
     * Import classes at the top of a PHP file, keeping its imports sorted.
     *
     * @label Import Classes
     *
     * @ai A class that is already imported reuses its name. A class whose
     *     short name is taken by another import is written in full, with a
     *     leading backslash, instead of being aliased.
     *
     * @param  string  $source  The file's source
     * @param  list<string>  $classes  The classes to import, e.g. ['App\Models\Client']
     * @return array{string, array<string, string>} The new source, and the placeholder for each class mapped to how to write it
     */
    protected function importClasses(string $source, array $classes): array
    {
        preg_match_all('/^use\s+\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?;[ \t]*\n/m', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $imported = [];

        foreach ($matches as $match) {
            // PHP leaves out an unmatched last group, so isset() is enough.
            $alias = isset($match[2]) ? $match[2][0] : class_basename($match[1][0]);
            $imported[$alias] = $match[1][0];
        }

        $references = [];
        $new = [];

        foreach ($classes as $class) {
            $alias = array_search($class, $imported, true);

            if (is_string($alias)) {
                $references["{{ {$class} }}"] = $alias;
            } elseif (isset($imported[class_basename($class)])) {
                $references["{{ {$class} }}"] = '\\'.$class;
            } else {
                $imported[class_basename($class)] = $class;
                $new[] = $class;
                $references["{{ {$class} }}"] = class_basename($class);
            }
        }

        if ($new === []) {
            return [$source, $references];
        }

        if ($matches === []) {
            $return = strpos($source, "\nreturn ");
            $at = $return === false ? strlen("<?php\n") : $return + 1;

            return [substr($source, 0, $at).$this->useStatements($new)."\n\n".substr($source, $at), $references];
        }

        // Remove every existing import, then write them all back sorted
        // where the first one was.
        $lines = [];

        foreach (array_reverse($matches) as $match) {
            $lines[] = rtrim($match[0][0]);
            $source = substr($source, 0, $match[0][1]).substr($source, $match[0][1] + strlen($match[0][0]));
        }

        $lines = [...$lines, ...array_map(fn (string $class) => "use {$class};", $new)];
        usort($lines, fn (string $a, string $b) => $this->compareImports($a, $b));

        $at = $matches[0][0][1];

        return [substr($source, 0, $at).implode("\n", $lines)."\n".substr($source, $at), $references];
    }

    /**
     * Get sorted use statements for a list of classes.
     *
     * @label Use Statements
     *
     * @param  list<string>  $classes  The classes, e.g. ['Illuminate\Support\Facades\Schema']
     */
    protected function useStatements(array $classes): string
    {
        $lines = array_map(fn (string $class) => "use {$class};", array_values(array_unique($classes)));

        usort($lines, fn (string $a, string $b) => $this->compareImports($a, $b));

        return implode("\n", $lines);
    }

    /**
     * Compare two use statements the way Pint's ordered_imports rule sorts them.
     *
     * @label Compare Imports
     *
     * @ai PHP-CS-Fixer's OrderedImportsFixer (which Pint runs) compares
     *     case-insensitively with each backslash read as a space, so
     *     App\Models\Client sorts before App\Models1.
     *
     * @param  string  $a  The first statement, e.g. use App\Models\Client;
     * @param  string  $b  The second statement
     */
    protected function compareImports(string $a, string $b): int
    {
        $name = fn (string $line): string => str_replace('\\', ' ', (string) preg_replace('/^use\s+\\\\?|;$/', '', $line));

        return strcasecmp($name($a), $name($b));
    }

    /**
     * Print what the developer still has to do.
     *
     * @label Print Next Steps
     *
     * @param  NewType  $type  The new type
     */
    protected function printNextSteps(array $type): void
    {
        $short = class_basename($type['owner']);
        $steps = [];

        if (! isset(class_uses_recursive($type['owner'])[HasSettings::class])) {
            $steps[] = "Add the trait to [{$type['owner']}]: use Coyote6\\LaravelSettings\\Concerns\\HasSettings; then use HasSettings; in the class.";
        }

        if (! is_subclass_of($type['owner'], Authenticatable::class)) {
            $steps[] = "Say how to find the current {$this->ownerWords($type)}, in AppServiceProvider::boot(): "
                ."Settings::resolveOwnerUsing({$short}::class, fn () => ...);";
        }

        $steps[] = "Give each case of [{$type['enum']}] a default in default(); they all start as null.";

        $this->components->info('Next steps');
        $this->components->bulletList($steps);
    }

    /**
     * Get the owner model's name in lowercase words, e.g. team member.
     *
     * @label Owner Words
     *
     * @param  NewType  $type  The new type
     */
    protected function ownerWords(array $type): string
    {
        return Str::lower(Str::headline(class_basename($type['owner'])));
    }

    /**
     * Get the kind of key the owner model uses, as foreignIdFor() sees it.
     *
     * @label Key Type
     *
     * @param  Model  $owner  An instance of the owner model
     * @return string One of integer, ulid, uuid or string
     */
    protected function keyType(Model $owner): string
    {
        $traits = class_uses_recursive($owner);

        return match (true) {
            $owner->getKeyType() === 'int' => 'integer',
            isset($traits[HasUlids::class]) => 'ulid',
            isset($traits[HasUuids::class]) => 'uuid',
            default => 'string',
        };
    }

    /**
     * Get the date prefix for the new migration, one second later than any migration already using it.
     *
     * @label Migration Prefix
     *
     * @ai The same rule as Laravel's MigrationCreator::getDatePrefix(), so
     *     two types added in the same second still migrate in order.
     *
     * @param  Filesystem  $files  The filesystem used to look for migrations
     */
    protected function migrationPrefix(Filesystem $files): string
    {
        $date = Date::now();

        while ($files->glob(database_path('migrations/'.$date->format('Y_m_d_His').'_*.php')) !== []) {
            $date = $date->addSecond();
        }

        return $date->format('Y_m_d_His');
    }

    /**
     * Find migrations that already create the given table.
     *
     * @label Existing Migrations
     *
     * @param  Filesystem  $files  The filesystem used to search
     * @param  string  $table  The table, e.g. client_settings
     * @return list<string>
     */
    protected function existingMigrations(Filesystem $files, string $table): array
    {
        return array_values($files->glob(database_path("migrations/*_create_{$table}_table.php")));
    }

    /**
     * List the models in app/Models, for the model question's suggestions.
     *
     * @label Available Models
     *
     * @ai Laravel's own helper moved from possibleModels() to
     *     findAvailableModels() in 12.38, and this package supports 12.0,
     *     so it lists the folder itself, as the older helper did.
     *
     * @return list<string>
     */
    protected function availableModels(): array
    {
        $path = app_path('Models');

        if (! is_dir($path)) {
            return [];
        }

        $models = array_map(fn (SplFileInfo $file) => $file->getBasename('.php'), (new Filesystem)->files($path));

        sort($models);

        return $models;
    }
}
