<?php

namespace App\Modules\Payments\Gateways;

use RuntimeException;

final class InvalidWebhookSignature extends RuntimeException {}
