<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = (string) ($_POST['_csrf'] ?? '');
    if ($submitted === '' || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your session expired. Please go back and try again.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function cart_count(): int
{
    return array_sum(array_map('intval', $_SESSION['cart'] ?? []));
}

function asset(string $path): string
{
    return '/assets/' . rawurlencode($path);
}

function format_price(float $amount, ?string $currency = null): string
{
    $currency = strtoupper(trim($currency ?: (string) (getenv('APP_CURRENCY') ?: 'BDT')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = 'BDT';
    }
    $formatted = number_format(max(0, $amount), 2, '.', ',');
    return $currency === 'BDT' ? '৳' . $formatted : $currency . ' ' . $formatted;
}
