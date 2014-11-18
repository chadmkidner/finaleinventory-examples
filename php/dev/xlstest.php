<?php

my $xls = Spreadsheet::ParseExcel::Simple->read('http://www.bewickedcostumes.com/download/Bewicked_stock.xls');
foreach my $sheet ($xls->sheets) {
     while ($sheet->has_data) {  
         my @data = $sheet->next_row;
         print @data[0];
     }
  }
  
?>