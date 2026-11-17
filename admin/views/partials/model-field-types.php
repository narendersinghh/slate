<?php
/**
 * How each custom field type is presented in the builder: type => [label, icon, palette group, one-line hint].
 * The stored type keys are ContentModel::FIELD_TYPES; this only adds friendly names and icons.
 */
return [
    'text' => ['Text', 'type', 'Basic', 'One line of text'],
    'textarea' => ['Long text', 'file-text', 'Basic', 'Several lines, no formatting'],
    'richtext' => ['Rich text', 'edit', 'Basic', 'Formatted text with links and lists'],
    'number' => ['Number', 'sort', 'Basic', 'A price, a year, a quantity'],
    'email' => ['Email', 'mail', 'Basic', 'An email address'],
    'url' => ['Link', 'globe', 'Basic', 'A web address'],
    'tel' => ['Phone', 'message', 'Basic', 'A phone number'],
    'select' => ['Dropdown', 'chevron-down', 'Choice', 'Pick one from a list'],
    'radio' => ['Radio buttons', 'check-circle', 'Choice', 'Pick one, all options visible'],
    'checkbox' => ['Checkboxes', 'check', 'Choice', 'Pick several'],
    'toggle' => ['Yes / no', 'sliders', 'Choice', 'An on and off switch'],
    'date' => ['Date', 'calendar', 'Choice', 'A day'],
    'datetime' => ['Date and time', 'clock', 'Choice', 'A day and a time'],
    'color' => ['Colour', 'palette', 'Choice', 'A colour value'],
    'image' => ['Image', 'image', 'Media', 'One image from the media library'],
    'gallery' => ['Gallery', 'grid', 'Media', 'Several images'],
    'file' => ['File', 'file', 'Media', 'A PDF or any other file'],
    'relation' => ['Related content', 'link', 'Connections', 'Links to other posts or pages'],
    'taxonomy' => ['Terms', 'tag', 'Connections', 'Terms from a taxonomy'],
    'form' => ['Form', 'inbox', 'Connections', 'One of your forms'],
    'repeater' => ['Repeater', 'rows', 'Layout', 'A list of rows with their own fields'],
    'tab' => ['Tab', 'columns', 'Layout', 'Groups the fields below it in a tab'],
    'code' => ['Code / HTML', 'code', 'Layout', 'Raw HTML or embed code'],
];
