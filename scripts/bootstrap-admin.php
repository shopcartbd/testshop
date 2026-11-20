<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../app/Database.php';

function prompt_line(string $label): string
{
    fwrite(STDERR, $label);
    $value = fgets(STDIN);
    if ($value === false) throw new RuntimeException('Input was not received.');
    return trim($value);
}

function prompt_secret(string $label): string
{
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new RuntimeException('Run this command in an interactive terminal so the password is not echoed.');
    }
    fwrite(STDERR, $label);
    system('stty -echo');
    try {
        $value = fgets(STDIN);
    } finally {
        system('stty echo');
        fwrite(STDERR, "\n");
    }
    if ($value === false) throw new RuntimeException('Password input was not received.');
    return rtrim($value, "\r\n");
}

try {
    $name = trim(preg_replace('/\s+/u', ' ', prompt_line('Owner display name: ')) ?? '');
    $email = strtolower(prompt_line('Owner email: '));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 320) {
        throw new InvalidArgumentException('Enter a valid name and email address.');
    }
    $password = prompt_secret('New owner password (minimum 16 characters): ');
    $confirm = prompt_secret('Confirm password: ');
    if (strlen($password) < 16 || strlen($password) > 200 || !hash_equals($password, $confirm)) {
        throw new InvalidArgumentException('Password confirmation failed or password length is invalid.');
    }

    $db = Database::connection();
    $db->beginTransaction();
    try {
        $db->query("SELECT pg_advisory_xact_lock(hashtext('allstore-first-owner-bootstrap'))");
        $count = (int) $db->query("SELECT count(*) FROM app.accounts WHERE role IN ('admin','owner')")->fetchColumn();
        if ($count !== 0) throw new DomainException('A privileged account already exists; bootstrap is locked.');
        $insert = $db->prepare("INSERT INTO app.accounts(email,password_hash,display_name,role) VALUES(:email,:hash,:name,'owner') RETURNING id");
        $insert->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'name' => $name]);
        $id = (string) $insert->fetchColumn();
        $audit = $db->prepare("INSERT INTO app.audit_logs(actor_account_id,action,entity_type,entity_id,details) VALUES(:actor,'owner.bootstrap','account',:entity,'{}'::jsonb)");
        $audit->execute(['actor' => $id, 'entity' => $id]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    $password = $confirm = '';
    fwrite(STDOUT, "Initial owner account created. Sign in at /login and review the admin account list.\n");
} catch (InvalidArgumentException|DomainException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    error_log('AllStore owner bootstrap failed: ' . get_class($error));
    fwrite(STDERR, "Bootstrap failed. Check required migrations, DB connectivity and the runtime role grants; no password or SQL details were printed.\n");
    exit(1);
}
