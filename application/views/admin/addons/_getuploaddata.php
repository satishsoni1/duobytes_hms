<div class="row d-flex flex-wrap">
    <?php
    if (!empty($all_contents)) {
        foreach ($all_contents as $content_key => $content_value) {
    ?>
            <div class="col-md-4 col-lg-4 col-sm-6 d-flex align-items-stretch mb10">
                <div class="card addon-services">
                    <div class="card-body">
                        <div class="row m-b-30">
                            <div class="col-md-3 col-lg-3 col-sm-12 col-12 text-center">
                                <div class="new-arrival-product mb-sm-1">
                                    <div class="new-arrivals-img-contnent">
                                        <img class="img-fluid" src="<?php echo $this->media_storage->getImageURL($content_value->image) ?>" alt="">
										<?php if (!IsNullOrEmptyString($content_value->current_version)) { ?>
                                        <h6 class="mb5"><?php echo $this->lang->line('version'); ?> <span class="price-num ps-1"><?php echo $content_value->current_version; ?></span></h6>
										<?php } ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-9 col-lg-9 col-sm-12 col-12">
                                <div class="addon-card">
                                    <h5 class="bmedium mb-1"><?php echo $content_value->name; ?></h5>
                                    <p class="addon-desc"><?php echo $content_value->description; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="rate">
                                        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                                            <div class="ms-auto">
                                                <?php

                                                $check_folder_exists = FCPATH . "./addons/" . $content_value->directory;
                                                if (!is_dir($check_folder_exists)) {
                                                ?>
                                                    <a target="_blank" href="<?php echo $content_value->article_link ; ?>" class="btn btn-primary btn-sm"><?php echo $this->lang->line('buy_now');?></a>
                                                <?php
                                                } elseif (is_dir($check_folder_exists) && IsNullOrEmptyString($content_value->current_version)) {
                                                ?>
                                                    <button type="button" data-product-id="<?php echo $content_value->product_id; ?>" data-directory="<?php echo $content_value->directory; ?>" class="btn btn-success  btn-sm install" data-loading-text="<i class='fa fa-circle-o-notch fa-spin'></i> <?php echo $this->lang->line('please_wait'); ?>"><?php echo $this->lang->line('install');?></button>

                                                    <?php
                                                }

                                                ?>
                                            </div>
                                        </div>
                                    </div>
                    </div>
                </div>
            </div>

        <?php

        }
    } else {
        ?>
        <div class="col-12 col-sm-6 col-md-12">
            <div class="alert alert-info">
                <?php echo $this->lang->line('no_record_found'); ?>
            </div>
        </div>
    <?php
    }
    ?>
</div>