<?php

declare(strict_types=1);

/**
 * Öffentliche Modulfunktionen werden von Symcon als PREFIX_Methode exportiert und dürfen
 * nur Parameter vom Typ bool, int, float oder string haben. Sonst meldet der Kernel beim
 * Laden der Bibliothek „Parameter … has no type hint or an unsupported type hint" und legt
 * die Funktion gar nicht an (STV_SendRestAPIRequest bis 2.10 build 25).
 *
 * Aufruf: php tests/check-public-signatures.php
 */

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/Sony Discovery/module.php';

const ERLAUBT = ['bool', 'int', 'float', 'string'];

foreach ([SonyTV::class, SonyDiscovery::class] as $klasse) {
    $rk = new ReflectionClass($klasse);
    foreach ($rk->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
        // Rückrufe des SDK (Create, ApplyChanges, RequestAction …) sind keine exportierten Funktionen
        if ($methode->getDeclaringClass()->getName() !== $klasse || method_exists(IPSModuleStrict::class, $methode->getName())) {
            continue;
        }
        foreach ($methode->getParameters() as $parameter) {
            // die exportierten Funktionen kennen keine Vorgabewerte: STV_…($id) ohne den Parameter endet mit "Parameter count does not match"
            pruefe(!$parameter->isOptional(), sprintf('%s::%s(): Parameter $%s hat keinen Vorgabewert', $klasse, $methode->getName(), $parameter->getName()));
            $typ  = $parameter->getType();
            $name = $typ instanceof ReflectionNamedType ? $typ->getName() : (string)$typ;
            pruefe(
                $typ instanceof ReflectionNamedType && in_array($name, ERLAUBT, true),
                sprintf('%s::%s(): Parameter $%s hat einen exportierbaren Typ (%s)', $klasse, $methode->getName(), $parameter->getName(), $name === '' ? 'ohne' : $name)
            );
        }
    }
}

ergebnis();
