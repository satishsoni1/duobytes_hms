<?php
/** Patient address dropdowns on staff pages (included by layout/footer.php). See backend/js/sh-address.js. */
$CI =& get_instance();
if (!$CI->session->userdata('hospitaladmin')) { return; }
$CI->load->model('geo_model');
$CI->geo_model->ensure_schema();
?>
<script>window.SH_GEO_DEFAULTS = <?php echo json_encode($CI->geo_model->defaults()); ?>;</script>
<script src="<?php echo base_url('backend/js/sh-address.js'); ?>"></script>
