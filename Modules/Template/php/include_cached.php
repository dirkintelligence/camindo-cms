if (!empty($_r)) { 
  $this->cms->includeDepth++; 
  require $this->cms->templatePath . "/{$_r}.php";
  $this->cms->includeDepth--; 
}
$this->cms->includeEnd();
