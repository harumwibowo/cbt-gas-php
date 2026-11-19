<?php
/**
 * Exception domain dengan status HTTP yang jelas (Bagian 3.2).
 *
 * api.php memetakan kelas-kelas ini ke status:
 *   ValidationException  -> 422
 *   ForbiddenException   -> 403 (RbacDenied mewarisinya)
 *   NotFoundException    -> 404
 *   ConflictException    -> 409
 *   ServerErrorException -> 500
 * Pesan yang dikirim ke klien tetap disaring client_error_message().
 */
declare(strict_types=1);

class ApiException extends RuntimeException
{
    public function httpStatus(): int
    {
        return 500;
    }
}

final class ValidationException extends ApiException
{
    public function httpStatus(): int
    {
        return 422;
    }
}

final class NotFoundException extends ApiException
{
    public function httpStatus(): int
    {
        return 404;
    }
}

final class ConflictException extends ApiException
{
    public function httpStatus(): int
    {
        return 409;
    }
}

class ForbiddenException extends ApiException
{
    public function httpStatus(): int
    {
        return 403;
    }
}

final class ServerErrorException extends ApiException
{
    public function httpStatus(): int
    {
        return 500;
    }
}
