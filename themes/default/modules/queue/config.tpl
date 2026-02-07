<!-- BEGIN: main -->
<form action="{FORM_ACTION}" method="post" class="form-horizontal">
    <input type="hidden" name="checkss" value="{CHECKSS}" />
    <div class="panel panel-default">
        <div class="panel-heading">{LANG.config}</div>
        <div class="panel-body">
            <div class="form-group">
                <label class="col-sm-4 control-label"><strong>{LANG.config_active}</strong></label>
                <div class="col-sm-8">
                    <input type="checkbox" name="active" value="1" {ACTIVE_CHECKED} />
                </div>
            </div>
            
            <div class="form-group">
                <label class="col-sm-4 control-label"><strong>{LANG.config_driver}</strong></label>
                <div class="col-sm-8">
                    <select name="driver" class="form-control" id="queue_driver">
                        <option value="database" {DRIVER_DATABASE_CHECKED}>Database (MySQL/MariaDB)</option>
                        <option value="redis" {DRIVER_REDIS_CHECKED}>Redis</option>
                    </select>
                </div>
            </div>

            <div id="redis_config" style="display: {REDIS_DISPLAY}">
                <hr>
                <h4>{LANG.config_redis_settings}</h4>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{LANG.config_redis_host}</label>
                    <div class="col-sm-8">
                        <input type="text" name="redis_host" value="{DATA.redis_host}" class="form-control" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{LANG.config_redis_port}</label>
                    <div class="col-sm-8">
                        <input type="number" name="redis_port" value="{DATA.redis_port}" class="form-control" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{LANG.config_redis_pass}</label>
                    <div class="col-sm-8">
                        <input type="password" name="redis_pass" value="{DATA.redis_pass}" class="form-control" autocomplete="off" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{LANG.config_redis_db}</label>
                    <div class="col-sm-8">
                        <input type="number" name="redis_db" value="{DATA.redis_db}" class="form-control" />
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{LANG.config_redis_prefix}</label>
                    <div class="col-sm-8">
                        <input type="text" name="redis_prefix" value="{DATA.redis_prefix}" class="form-control" />
                    </div>
                </div>
            </div>

            <div class="form-group text-center">
                <input type="submit" name="save" value="{LANG.save}" class="btn btn-primary" />
            </div>
        </div>
    </div>
</form>

<script type="text/javascript">
$(document).ready(function() {
    function toggleRedisConfig() {
        if ($('#queue_driver').val() === 'redis') {
            $('#redis_config').show();
        } else {
            $('#redis_config').hide();
        }
    }
    
    $('#queue_driver').change(function() {
        toggleRedisConfig();
    });
    
    toggleRedisConfig();
});
</script>
<!-- END: main -->
