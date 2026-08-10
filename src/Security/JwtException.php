<?php

namespace Falco\Security;

/** Thrown by {@see JwtService} for invalid/expired tokens (`invalid_token`, `invalid_signature`, `expired`). */
final class JwtException extends \RuntimeException {}
