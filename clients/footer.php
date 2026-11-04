</main>
</div>

<div class="toast-container" id="toast-container"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="../datatables2/datatables.min.js"></script>
<script src="../assets/js/loanpro.js"></script>

<script>
$(function(){
  if($.fn.DataTable){
    $('.data-table').each(function(){
      if(!$.fn.DataTable.isDataTable(this)){
        $(this).DataTable({
          order:[[0,'desc']],
          pageLength:20,
          responsive:true,
          dom:'<"dt-top"lf>t<"pag-wrap"ip>',
          language:{
            search:'',
            searchPlaceholder:'Search…',
            lengthMenu:'Show _MENU_ records',
            info:'Showing _START_–_END_ of _TOTAL_',
            paginate:{
              previous:'<i class="fas fa-chevron-left"></i>',
              next:'<i class="fas fa-chevron-right"></i>'
            }
          }
        });
      }
    });
  }
});
</script>

</body>
</html>