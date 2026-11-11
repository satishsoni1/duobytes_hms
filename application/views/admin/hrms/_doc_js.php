<?php /** Upload + delete handlers for staff HR documents (profile tab and Staff documents page). */ ?>
<script>
$(function () {
  $(document).off('submit.shHrUp').on('submit.shHrUp', '#shHrUploadForm', function (e) {
    e.preventDefault();
    shHr.postForm(this, { done: function () { window.location.reload(); } });
  });
  $(document).off('click.shHrDel').on('click.shHrDel', '.sh-hr-del', function () {
    shHr.post(base_url + 'admin/hrms/delete_document/' + $(this).data('id'), {}, { confirm: 'Delete this document? It will no longer show on the staff file.', done: function () { window.location.reload(); } });
  });
});
</script>
