<?php

declare(strict_types=1);

namespace Refaxination\Enum;

enum SourceType: string
{
    case Attachment  = 'attachment';
    case PostContent = 'post_content';
    case Postmeta    = 'postmeta';
    case Options     = 'options';
    case Tsf         = 'tsf';
    case Ssp         = 'ssp';
    case Acf         = 'acf';
    case Yoast       = 'yoast';
    case Custom      = 'custom';
}
