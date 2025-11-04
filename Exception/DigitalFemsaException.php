<?php

namespace DigitalFemsa\Payments\Exception;

class DigitalFemsaException extends \Exception
{
    public const INVALID_PHONE_MESSAGE = 'Teléfono no válido.
        El teléfono debe tener al menos 10 caracteres.
        Los caracteres especiales se desestimarán, sólo se puede ingresar como
        primer caracter especial: +';
}
