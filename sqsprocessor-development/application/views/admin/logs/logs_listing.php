<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<?php 
$base_url = $this->config->config['base_url']; ?>
<html>
    <head>
        <title>Logs Listing</title>
        <style type="text/css">
            .fileinput.fileinput-new { margin: 0; }
            .fileinput-new-parent { line-height: 0; }
        </style>
    </head>
    <body>
        <h2>Logs Listing</h2>
        <br />
        <div class="search-filter-sort pull-right col-md-12">
            <div class="dataTables_filter main-search search-icon">
                <label>
                    Search:
                    <input type="text" name="search_text" id="search_btn"/>
                </label>
            </div>
        </div>
       
        <div class="row">
            <div class="col-md-12">
                <table class="table table-bordered" id="log_file">
                    <thead>
                        <tr>
                            <th width="10%">Sr. No.</th>
                            <th width="20%">Log File Extension</th>
                            <th width="50%">Log File Name</th>
                        </tr>
                    </thead>
                    <tbody id='tbl_body'>
                                        
                    </tbody>
                </table>
            </div>
        </div>
        
        <script> 
        $(document).ready(function () { 
            $('#search_btn').val(''); 
            var search_key = '';
           get_log_listing(search_key);
           $('#search_btn').keydown(function (e) {
                search_key  = $(this).val();
                if (e.keyCode == 13) {
                     get_log_listing(search_key);
                }
           })
           
        });
        function  get_log_listing(search_key) {
            var base_url  =  '<?php echo $base_url; ?>';
            $.ajax({
                url: '<?= $base_url ?>/logs/filter_log',
                method: 'POST',
                data:{'search' :  search_key},
                success: function(result){
                    $('#tbl_body').html(result);
                }
            }); 
        }
        </script>
        <style>
        #log_file_length > label {
            display: none;
        }
        </style>
    </body>
</html>