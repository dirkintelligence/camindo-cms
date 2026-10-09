<?php
/**
 * api login|logout|whoami|call|methods|describe - die API auf der Kommandozeile
 *
 *     api login --admin=NAME|ID [--lifetime=SEK]     Session anlegen (Oberfläche cli, ohne
 *                                                    Passwort - siehe ApiSession::runAs())
 *     api call METHODE ['{"param": …}'] [--params-file=DATEI]
 *     api whoami | methods | describe METHODE
 *     api logout                                     Session beenden, Arbeitskopien verwerfen
 *
 * Außer login brauchen alle Befehle die Session: --session=ID oder die
 * Umgebungsvariable CAMINDO_API_SESSION. Ausgabe ist JSON (bei call die ganze
 * Antwort von Api::call()); Exit-Code 1, wenn die API einen Fehler meldet.
 *
 * Die Gruppe des Admins muss die Oberfläche "Kommandozeile" benutzen dürfen.
 */
namespace Camindo\Core\Cli;

use Camindo\Core\Api\Api;
use Camindo\Core\Api\ApiException;
use Camindo\Core\Api\ApiSession;

class ApiCommand extends Command {

    private const USAGE = 'usage: api login --admin=NAME [--lifetime=SEC] | logout | whoami | methods'
        . ' | describe METHOD | call METHOD [JSON] [--params-file=FILE]   (--session=ID or CAMINDO_API_SESSION)';

    public function run(array $args, array $options): int
    {
        $what = (string)($args[0] ?? '');
        $apiSession = $this->get(ApiSession::class);
        try {
            if ($what === 'login') {
                $admin = is_string($options['admin'] ?? null) ? trim($options['admin']) : '';
                if ($admin === '') return $this->usage(self::USAGE);
                $lifetime = is_string($options['lifetime'] ?? null) ? (int)$options['lifetime'] : null;
                return $this->json(['ok' => true, 'data' => $apiSession->runAs($admin, 'cli', $lifetime)]);
            }
            if (!in_array($what, ['logout', 'whoami', 'methods', 'describe', 'call'], true)) {
                return $this->usage(self::USAGE);
            }

            $sessionId = is_string($options['session'] ?? null) ? $options['session'] : (string)getenv('CAMINDO_API_SESSION');
            if ($sessionId === '') return $this->usage('missing --session=ID (or CAMINDO_API_SESSION)');
            $apiSession->resume($sessionId);

            if ($what === 'logout') {
                return $this->json(['ok' => true, 'data' => $apiSession->logout()]);
            }
            $api = $this->get(Api::class);
            return match ($what) {
                'whoami'   => $this->json($api->call('system.whoami')),
                'methods'  => $this->json($api->call('system.methods')),
                'describe' => $this->json($api->call('system.describe', ['method' => (string)($args[1] ?? '')])),
                'call'     => $this->json($api->call((string)($args[1] ?? ''), $this->params($args, $options))),
            };
        }
        catch (ApiException $e) {
            return $this->json(['ok' => false, 'error' => $e->errorCode(), 'message' => $e->getMessage(), 'errors' => $e->errors()]);
        }
    }

    /**
     * Parameter von call: JSON als zweites Argument oder aus --params-file.
     */
    private function params(array $args, array $options): array
    {
        $json = (string)($args[2] ?? '');
        $file = is_string($options['params-file'] ?? null) ? $options['params-file'] : '';
        if ($file !== '') {
            if (!is_readable($file)) throw new ApiException(ApiException::INVALID_PARAM, "cannot read {$file}");
            $json = (string)file_get_contents($file);
        }
        if (trim($json) === '') return [];
        $params = json_decode($json, true);
        if (!is_array($params)) throw new ApiException(ApiException::INVALID_PARAM, 'parameters must be a JSON object');
        return $params;
    }

    /** Antwort als JSON ausgeben; Exit-Code nach 'ok' */
    private function json(array $result): int
    {
        $this->out((string)json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return empty($result['ok']) ? Cli::ERROR : Cli::OK;
    }
}
