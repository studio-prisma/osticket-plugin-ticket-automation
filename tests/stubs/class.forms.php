<?php
// Stand-ins for the field classes config.php instantiates. They keep their
// settings so the tests can read keys, defaults and choices.
class StubField
{
    public $ht;
    function __construct(array $ht = array()) { $this->ht = $ht; }
    function get($k) { return $this->ht[$k] ?? null; }
}
class SectionBreakField extends StubField {}
class BooleanField extends StubField {}
class ChoiceField extends StubField {}
class TextboxField extends StubField {}
class TextareaField extends StubField {}
