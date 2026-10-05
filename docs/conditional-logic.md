# Conditional logic

A field's `conditional` property holds a rule deciding whether it is shown:

```json
{
  "show_when": {
    "all": [
      { "field": "contact_method", "op": "equals", "value": "email" }
    ]
  }
}
```

- **Combinators:** `all` (every condition) or `any` (at least one). A rule with
  a non-null `any` key uses `any`; otherwise `all`.
- **Operators** (`RuleEvaluator::OP_*`): `equals`, `not_equals`, `contains`,
  `is_empty`, `is_not_empty`.
- **Values are compared as strings.** For multi-value fields, `equals` and
  `contains` match when any selected entry matches.
- **Missing fields** evaluate as null: `equals` is false, `is_empty` is true.
- **A null, empty or malformed rule always shows the field.** A condition with
  no field or operator, or with an unknown operator, never matches.

## RuleEvaluator

```php
$evaluator = new RuleEvaluator();
$evaluator->shouldShow($field->conditional, $submittedValues); // bool
RuleEvaluator::operatorVocabulary(); // list of ['op', 'label', 'needs_value']
```

The same semantics are implemented client-side by the host's
`FormConditional.js`; change both together.

## On the server

`FormSubmissionService` evaluates every rule against the POST data. Hidden
fields are left out of validation and dropped from the submitted values.
`FormMarkup` writes the rule to `data-form-conditional` and adds `hidden` to
fields whose rule fails against the form's current values.

## ConditionalRulesParser

Turns the field editor's POST shape into a rule:

```php
ConditionalRulesParser::parse([
    'combinator' => 'any',
    'conditions' => [
        ['field' => 'contact_method', 'op' => 'equals', 'value' => 'email'],
        ['field' => '', 'op' => 'equals'],          // dropped: no field
        ['field' => 'phone', 'op' => 'is_empty', 'value' => 'x'], // value stripped
    ],
]);
// ['show_when' => ['any' => [
//     ['field' => 'contact_method', 'op' => 'equals', 'value' => 'email'],
//     ['field' => 'phone', 'op' => 'is_empty'],
// ]]]
```

It returns `null` (always show) when nothing usable remains, or when the input
is not an array. Non-scalar values are treated as empty.
