<?php

namespace App\Enums;

enum TokenStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
}
