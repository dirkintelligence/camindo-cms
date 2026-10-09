if (!empty($_r)) { 
  $this->cms->includeDepth++; 
  require $this->cms->templatePath . "/{$_r}.php";
  $this->cms->includeDepth--; 

  if (empty($this->cms->returnAfterSuccess)) { 
      exit(); 
  } else { 
      $this->cms->returnAfterSuccess = false; 
  } 
}