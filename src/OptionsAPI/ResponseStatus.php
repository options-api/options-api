<?php

declare(strict_types=1);

namespace OptionsAPI;

enum ResponseStatus: int
{
    case NOT_MODIFIED = 403;
    case NOT_FOUND = 404;
}
