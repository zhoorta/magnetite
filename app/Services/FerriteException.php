<?php

namespace App\Services;

use RuntimeException;

/** Ferrite could not be used; the message is meant for the person running the scan or reading the player. */
class FerriteException extends RuntimeException {}
