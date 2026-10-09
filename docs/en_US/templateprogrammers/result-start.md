# CMS:RESULT_START

Marks the beginning of a loop for processing CMS:SELECT results.

```cms
(CMS:RESULT_START)
```

***No method call*** – the effect lies solely in the PHP snippet  
***PHP snippet:*** `loop_start` (after)

## Parameters

This command has no parameters.

## Usage

Opens the loop over the hits of a `CMS:SELECT` (or `CMS:SELECT_LANGVERSION`,
`CMS:USER_BASKET function=load`). It belongs in the ***list template***; everything between
`CMS:RESULT_START` and `CMS:RESULT_END` is executed for each hit, and the commands in it
read from the current row:

```cms
<table>
(CMS:RESULT_START)
<tr>
  <td><?php echo $this->cms->row_no + 1; ?></td>
  <td><a href="(CMS:URL)">(CMS:TEXT title)</a></td>
  <td>(CMS:DATE online_at)</td>
</tr>
(CMS:RESULT_END)
</table>
```

Within the loop, the following are available: `$cms` (the current row as an array),
`$this->cms->row_no` (zero-based row number), `$this->db->num_rows` (hits on
this page) and `$this->db->num_rows_unlimited` (total number with `count=true`).

The command has no parameters and creates no method call – it is compiled directly into a
`for` loop.

---

[← Overview](README.md)
