<?php
/**
 * template commands [--lang=LANG | --all --out=DIR]
 *
 *     commands  the CMS command definitions (core commands.php plus the commands of
 *               the active modules) as JSON - for editor support such as the VS Code
 *               extension camindo/camindo-vscode (completion, hover).
 *               --lang  backend language of the texts (default de_DE); the texts in
 *                       commands.php are snippet references (#template.cmd_…,
 *                       Template::commandText()), module commands may carry plain text
 *               --all   one file per backend language, commands.{lang}.json in --out
 *               without --all the JSON goes to STDOUT
 *
 * A project with its own modules writes its file this way and names it in the
 * extension setting camindo.commandsFile.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Cms;
use Camindo\Core\Modules\Template\Template;

class TemplateCommand extends Command {

    public function run(array $args, array $options): int
    {
        if (($args[0] ?? '') !== 'commands') {
            return $this->usage('usage: template commands [--lang=LANG | --all --out=DIR]');
        }
        $languages = $this->get(Cms::class)->backendLanguages();

        if (!empty($options['all'])) {
            $dir = rtrim((string)($options['out'] ?? ''), '/');
            if ($dir === '' || $options['out'] === true || !is_dir($dir)) {
                return $this->usage('--all needs --out=DIR (an existing directory)');
            }
            foreach ($languages as $lang) {
                $file = "$dir/commands.$lang.json";
                if (file_put_contents($file, $this->export($lang)) === false) {
                    $this->err("cannot write $file");
                    return Cli::ERROR;
                }
                $this->out($file);
            }
            return Cli::OK;
        }

        $lang = (string)($options['lang'] ?? 'de_DE');
        if (!in_array($lang, $languages, true)) {
            return $this->usage("unknown backend language '$lang' (" . implode(', ', $languages) . ')');
        }
        // STDOUT directly: --quiet must not swallow the data
        fwrite(STDOUT, $this->export($lang));
        return Cli::OK;
    }

    /**
     * JSON of all command definitions with the texts in one language.
     */
    private function export(string $lang): string
    {
        $template = $this->get(Template::class);
        $text = fn($value) => $template->commandText($value, $lang);

        $commands = [];
        foreach ($template->commandDefinitions() as $name => $def) {
            $params = [];
            foreach ((array)($def['params'] ?? []) as $param => $pdef) {
                $params[$param] = [
                    'description' => $text($pdef['description'] ?? ''),
                    'value'       => $text($pdef['value'] ?? ''),
                    'default'     => $text($pdef['default'] ?? ''),
                    'mandatory'   => !empty($pdef['mandatory']),
                    'subparam'    => (int)($pdef['subparam'] ?? 0),
                    'operator'    => !empty($pdef['operator']),
                ];
            }
            $commands[$name] = [
                'note'   => $text($def['note'] ?? ''),
                'do'     => (string)($def['do'] ?? ''),
                'oneof'  => (string)($def['oneof'] ?? ''),
                'params' => (object)$params,
            ];
        }
        return json_encode(['version' => CAMINDO_VERSION, 'lang' => $lang, 'commands' => $commands],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
