# Admin Entity List Standard v0.1

Use this pattern for admin changelists that represent a primary entity list.

- Set `list_fullwidth = True` to use the full content width.
- Set `list_horizontal_scrollbar_top = True` when a list can exceed the viewport width.
- Keep `search_fields` explicit and limited to the primary identifier plus the main display field.
- Add a short `search_help_text` in English that explains the supported search inputs.
- Prefer native Unfold filters over custom filters.
- Use dropdown filters for related fields and choices.
- Use numeric filters for numeric identifiers when `unfold.contrib.filters` is enabled.
- Set `list_filter_submit = True` for filters with typed input.
- Use `list_filter_sheet = False` when a persistent filter sidebar is more useful than the default sheet.
- Use `UNFOLD["TABS"]` only when a model has a small set of related list views that benefit from quick switching.

This standard is implemented only for the VLAN changelist in the current iteration.
