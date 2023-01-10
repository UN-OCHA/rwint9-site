ReliefWeb - Form module
=======================

This module provides various form improvements.

## Select, checkboxes, radios widgets

This module provides `select` form element template that allows adding attributes to the options.

It also modifies the checkboxes and radios widgets to also allow adding attributes.

## Javascript widgets

This module provides [javascript widgets](js) to enhance some form fields.

- [Autocomplete](js/widget.autocomplete.js): transforms a select widget into an autocomplete
- [Datepicker](js/widget.datepicker.js): transforms a date widget into a datepicker
- [Formatting](js/widget.formatting.js): provides text formatting buttons (ex: uppercase)
- [Length checker](js/widget.lengthchecker.js): Checks the length of a text field against a set of minimum and maximum length
- [Selection limit](js/widget.selectionlimit.js): Limit the selection of checkboxes to a set number


## Source information route

This module provides a [route and controller](src/Controller/NodeForm.php) to retrieve information about a source when selected in a form.

This is used to display editorial information on reports, jobs and training pages.

## Form helper

This module provides a [form helper](src/Helpers/FormHelper.php) to help removing or sorting options.

## Node preview - inline entity form

This module overrides the `node_preview` param converter to allow handling fields using inline entity forms, to allow to see the referenced entity value in the preview while preventing it from being created until the form is saved.

