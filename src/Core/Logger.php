<?php
declare(strict_types=1);
namespace AllStore\Core;

final class Logger
{
    private const LEVELS = ['debug'=>0,'info'=>1,'warning'=>2,'error'=>3,'critical'=>4];

    public static function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $min = self::LEVELS[strtolower((string)Config::get('log.level'))] ?? 1;
        if ((self::LEVELS[$level] ?? 1) < $min) { return; }
        $msg = Security::redact($message . ' ' . Helpers::json($context));
        $line = gmdate('c') . ' [' . strtoupper($level) . '] ' . $msg . PHP_EOL;
        error_log('AllStore ' . $line);
        $dir = (string)Config::get('log.path');
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        }
    }

    public static function exception(\Throwable $e): void
    {
        self::log('error', get_class($e) . ': ' . $e->getMessage(), [
            'file' => basename($e->getFile()), 'line' => $e->getLine(),
        ]);
    }

    public static function audit(string $action, string $entity = '', string $entityId = '', array $meta = [], string $severity = 'info'): void
    {
        try {
            $u = Auth::user();
            Database::insert('audit_logs', [
                'id'=>Helpers::uuid(), 'actor_id'=>$u['id'] ?? null, 'actor_email'=>$u['email'] ?? null,
                'action'=>$action, 'entity'=>$entity, 'entity_id'=>$entityId,
                'meta'=>Helpers::json($meta), 'ip_address'=>Request::ip(), 'severity'=>$severity,
                'created_at'=>Helpers::now(),
            ]);
        } catch (\Throwable) { /* never break the request because of logging */ }
        self::log($severity === 'critical' ? 'critical' : 'info', 'audit.' . $action, ['entity'=>$entity,'id'=>$entityId]);
    }
}
