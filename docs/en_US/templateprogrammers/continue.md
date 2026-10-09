# CMS:CONTINUE

Continues template processing despite a success template.

```cms
(CMS:CONTINUE)
```

***Method:*** `Cms::cmsContinue()`

## Parameters

This command has no parameters.

## Usage

Commands with `successtemplate` (`CMS:USER_LOGIN`, `CMS:USER_LOGIN_CODE`,
`CMS:USER_INPUT`, `CMS:USER_REGISTER`, `CMS:USER_REGISTER_CONFIRM`, `CMS:USER_PW`,
`CMS:USER_DELETE`, `CMS:EMAIL`) include their template in case of success and ***then end
the page***; likewise `CMS:USER_PROTECT` and `CMS:USER_PW` with their
`errortemplate`, and `CMS:USER_LOGIN` with the `codetemplate`. If `CMS:CONTINUE` stands before the
command, the template continues to run after the included template instead:

```cms
(CMS:CONTINUE)
(CMS:USER_INPUT
  container=comments
  submit="$submit"
  successtemplate=comment_saved
)
<h2>All comments</h2>
(CMS:SELECT comment_list
  filter[area]=comments
)
```

Without `CMS:CONTINUE`, nothing would be output after `comment_saved` – with it,
the confirmation appears above the list. The flag applies exactly once: the next
of these commands that actually includes a template uses it up.

---

[← Overview](README.md)
