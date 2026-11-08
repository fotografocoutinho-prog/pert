<?php

namespace App\Planning;

use RuntimeException;

/** As precedências do planeamento fecham um ciclo (A depende de B que depende de A). */
class ScheduleCycleException extends RuntimeException {}
