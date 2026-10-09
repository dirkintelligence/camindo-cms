# 9 Frontend editing and preview

## Frontend editing

With ***frontend editing*** you edit content directly while browsing the website: edit markers
appear next to the content. A click on one opens the backend with the input form of exactly this
record – ideal for correcting a typo you have just spotted on the website.

You switch frontend editing on in your [personal settings](./03-work-surface.md#personal-settings):

| Setting | Effect |
|---|---|
| “off” | no markers |
| “on - until logout or closing the browser” | markers until you sign out or close the browser |
| “on - permanently, also after logout” | markers also after signing out |

The setting only applies to the browser in which you made it. If your backend runs under a different
address than the websites (for example `cms.example.com`), saving a changed setting transfers it to all
websites: the browser visits each website briefly and then returns to the backend. Websites that cannot
be reached are skipped. Under “Frontend editing on the websites”, the button ***“Apply to all websites”***
repeats this synchronization – for example in another browser or when the markers have disappeared. The
buttons with a domain open only that website in a new tab. A preview also switches the markers on for
its website. Visitors to the website never see the markers. Where markers appear is determined by the
template programmer. To edit, you still need a valid sign-in and the necessary rights.

## Preview

The button ![visibility](../_/visibility.svg) “Preview” in the detail view shows a record the way the
website displays it – with its template and in the chosen language version. Unlike the website, the
preview also shows a record that is not yet published: deactivated, scheduled for later or expired.
This lets you check an article before you release it.

Lists within the preview page – such as “More articles” – show only published records in the preview,
exactly as on the website.

The preview is only reachable with a valid backend sign-in and is not indexed by search engines. If your
backend runs under a different address than the website, the preview opens under the main address of the
website – only there are the images accessible. The link then works only as long as you are signed in to
the backend, and for at most eight hours.
