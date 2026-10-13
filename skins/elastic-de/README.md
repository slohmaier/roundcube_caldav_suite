# elastic-de skin override — Accessibility (screen reader)

Site skin `elastic-de` (extends `elastic`). Contains the accessibility fix for the
**contact list** (CardDAV/caldav_suite) under NVDA.

## Problem

In the contact list, NVDA did not move to the next contact with arrow-down
("toolbar button unavailable" / only "text frame"); you had to manually switch to
focus mode with NVDA+Space (CapsLock+Space). The **mail list**, in contrast,
worked automatically.

## Cause

Roundcube renders the contact list as an ARIA listbox (`role="listbox"`, rows
`role="option"`), but places the DOM focus on a `<td>` cell instead of setting the
active option via `aria-activedescendant`. NVDA finds no active option and stays in
browse mode -> the arrow keys do nothing. In addition, the contact table was
**unnamed** (no `aria-labelledby`) -> NVDA only read "text frame". The mail list, in
contrast, is a plain table **with** `aria-labelledby` and a scroller with `tabindex="-1"`.

## Fix (templates/addressbook.html)

Aligned the contact list with the working mail list:
- removed `role="listbox"` from the table (plain table like `#messagelist`)
- added `aria-labelledby="aria-label-contactslist"` to the table (table is named)
- scroller div: `id="contacts-content"` + `tabindex="-1"` (like `#messagelist-content`)

## IMPORTANT: directory permissions

Roundcube runs as `www-data`. If the skin is outside the image (bind mount) and its
subdirectories are `750 root:root`, `www-data` **cannot** enter them and Roundcube
**silently** loads the stock template — every override change has no effect and no
error. After every change make sure:

    chmod -R a+rX <skin-directory>

Cross-check inside the container:

    su -s /bin/sh www-data -c 'test -r .../skins/elastic-de/templates/addressbook.html && echo OK'
