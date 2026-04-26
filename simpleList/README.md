# simpleList

Lightweight list & vocabulary editor for [Providence (CollectiveAccess)](https://collectiveaccess.org).

Renders a configured set of `ca_lists` as a hierarchical tree, lets editors
browse items lazily, jump to the standard Providence list/list-item editors,
and bulk-add new items from a textarea (one `idno` per line).

It is meant as a faster alternative to the built-in list editor when curators
need to skim or extend several controlled vocabularies side by side.

## Install

1. Copy or symlink this folder into `app/plugins/simpleList/` of your
   Providence install.
2. Edit `conf/simpleList.conf` to declare one or more *pages* and the
   `list_code`s they should expose (see comments inline).
3. Grant the `can_use_simplelist_plugin` action to the roles that should
   see the plugin (Manage → Access control → Roles).
4. Reload the menu — a new entry appears under the configured top-level
   menu (default: `find`).

## Configuration

A *page* groups a set of `ca_lists` under a single menu entry. Each page
must define:

| key   | meaning                                                                    |
|-------|----------------------------------------------------------------------------|
| label | string shown in the menu and as page title                                 |
| menu  | top-level menu key (`find`, `edit`, `manage`, `import`…)                   |
| lists | array of `ca_lists.list_code` values to expose                             |

Example:

```
pages = ["thesauri"]

thesauri = {
    label = "Thesauri",
    menu  = "find",
    lists = ["materials", "techniques", "subjects"]
}
```

## Permissions

The plugin defines a single role action: `can_use_simplelist_plugin`.
Both the menu entry and the editor controllers check this permission.

## Status

Provided as-is, GPL v3. Tested on Providence 2.x.
