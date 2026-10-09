# CMS:RESULT_END

Marks the end of a loop for processing CMS:SELECT results.

```cms
(CMS:RESULT_END)
```

***No method call*** – the effect lies solely in the PHP snippet  
***PHP snippet:*** `loop_end` (after)

## Parameters

This command has no parameters.

## Usage

Closes the loop opened with `CMS:RESULT_START`:

```cms
(CMS:RESULT_START)
  <li>(CMS:TEXT title)</li>
(CMS:RESULT_END)
```

Every `CMS:RESULT_START` needs exactly one `CMS:RESULT_END` in the same template – the
compiler turns them into `{` and `}`; a missing end leads to a PHP syntax error
when the page is called.

---

[← Overview](README.md)
