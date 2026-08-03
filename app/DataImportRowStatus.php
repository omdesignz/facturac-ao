<?php

namespace App;

enum DataImportRowStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Imported = 'imported';
}
