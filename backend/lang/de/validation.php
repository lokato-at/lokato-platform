<?php

/**
 * Minimale deutsche Validation-Strings für Lokato.
 *
 * Nur die Regeln, die in den aktuellen Request-validate()-Aufrufen genutzt
 * werden (login: email/string/required/email/max, password/min, ...).
 * Wenn neue Validation-Regeln dazukommen, hier ergänzen — sonst geht Laravel
 * auf den Translation-Key zurück (z. B. "validation.between").
 *
 * Reihenfolge orientiert sich an Laravels offiziellem lang/en/validation.php.
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Validation Language Lines
    |---------------------------------------------------------------------------
    */

    'accepted'       => 'Das Feld :attribute muss akzeptiert werden.',
    'active_url'     => 'Das Feld :attribute muss eine gültige URL sein.',
    'after'          => 'Das Feld :attribute muss ein Datum nach dem :date sein.',
    'before'         => 'Das Feld :attribute muss ein Datum vor dem :date sein.',
    'boolean'        => 'Das Feld :attribute muss wahr oder falsch sein.',
    'confirmed'      => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'date'           => 'Das Feld :attribute muss ein gültiges Datum sein.',
    'different'      => ':attribute und :other müssen sich unterscheiden.',
    'email'          => 'Das Feld :attribute muss eine gültige E-Mail-Adresse sein.',
    'exists'         => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'in'             => 'Der ausgewählte Wert für :attribute ist ungültig.',
    'integer'        => 'Das Feld :attribute muss eine ganze Zahl sein.',
    'ip'             => 'Das Feld :attribute muss eine gültige IP-Adresse sein.',
    'json'           => 'Das Feld :attribute muss ein gültiger JSON-String sein.',
    'numeric'        => 'Das Feld :attribute muss eine Zahl sein.',
    'present'        => 'Das Feld :attribute muss vorhanden sein.',
    'regex'          => 'Das Format von :attribute ist ungültig.',
    'required'       => 'Das Feld :attribute ist erforderlich.',
    'required_with'  => 'Das Feld :attribute ist erforderlich, wenn :values angegeben ist.',
    'same'           => 'Die Felder :attribute und :other müssen übereinstimmen.',
    'string'         => 'Das Feld :attribute muss ein String sein.',
    'unique'         => ':attribute ist bereits vergeben.',
    'url'            => 'Das Feld :attribute muss eine gültige URL sein.',
    'uuid'           => 'Das Feld :attribute muss eine gültige UUID sein.',

    'min' => [
        'numeric' => 'Das Feld :attribute muss mindestens :min sein.',
        'file'    => 'Das Feld :attribute muss mindestens :min Kilobytes groß sein.',
        'string'  => 'Das Feld :attribute muss mindestens :min Zeichen lang sein.',
        'array'   => 'Das Feld :attribute muss mindestens :min Einträge haben.',
    ],

    'max' => [
        'numeric' => 'Das Feld :attribute darf maximal :max sein.',
        'file'    => 'Das Feld :attribute darf maximal :max Kilobytes groß sein.',
        'string'  => 'Das Feld :attribute darf maximal :max Zeichen lang sein.',
        'array'   => 'Das Feld :attribute darf maximal :max Einträge haben.',
    ],

    'between' => [
        'numeric' => 'Das Feld :attribute muss zwischen :min und :max liegen.',
        'file'    => 'Das Feld :attribute muss zwischen :min und :max Kilobytes groß sein.',
        'string'  => 'Das Feld :attribute muss zwischen :min und :max Zeichen lang sein.',
        'array'   => 'Das Feld :attribute muss zwischen :min und :max Einträge haben.',
    ],

    'size' => [
        'numeric' => 'Das Feld :attribute muss :size sein.',
        'file'    => 'Das Feld :attribute muss :size Kilobytes groß sein.',
        'string'  => 'Das Feld :attribute muss :size Zeichen lang sein.',
        'array'   => 'Das Feld :attribute muss :size Einträge haben.',
    ],

    /*
    |---------------------------------------------------------------------------
    | Custom Validation Attributes
    |---------------------------------------------------------------------------
    | Damit ":attribute" als sprechender Feldname (z. B. "E-Mail-Adresse")
    | statt als Spalten-Name ("email") in der Fehlermeldung erscheint.
    */

    'attributes' => [
        'email'       => 'E-Mail-Adresse',
        'password'    => 'Passwort',
        'name'        => 'Name',
        'device_name' => 'Gerätename',
    ],

];
