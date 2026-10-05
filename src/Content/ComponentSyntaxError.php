<?php

declare(strict_types=1);

namespace Starlite\Content;

/** A malformed or unknown component in Markdown; the parser adds the file name. */
final class ComponentSyntaxError extends \RuntimeException
{
}
