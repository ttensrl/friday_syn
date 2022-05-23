<?php
global $sps, $sps_settings;
?>
<div class="wrap sps_content">
    <div class="setting-general">
        <?php echo '<h2>Sincronizza Tassonomie</h2>'; ?>
        <div id="progressbar" style="margin-bottom:20px">
            <div id="progresslabel">0%</div>
        </div>
        <div id="logger" class="target-message-spettacolo"></div>
        <p class="submit">
            <button class="button-primary sps_setting_save" type="button" id="sync_production_tax">
                Sincronizza Tassonomie
            </button>
        </p>
    </div>
</div>
