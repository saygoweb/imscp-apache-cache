<form name="apache_cache_edit" method="post"
      action="apache_cache_edit.php?type={TYPE}&amp;id={ID}">
    <table class="firstColFixed">
        <thead>
        <tr><th colspan="2">{DOMAIN_NAME}</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><label for="enabled">{TR_ENABLED}</label></td>
            <td><input type="checkbox" name="enabled" id="enabled"{ENABLED}></td>
        </tr>
        <tr>
            <td>
                <label for="wordpress_mode">{TR_WORDPRESS_MODE}</label>
                <span class="icon i_help" title="{TR_WORDPRESS_MODE_HELP}">?</span>
            </td>
            <td><input type="checkbox" name="wordpress_mode" id="wordpress_mode"{WORDPRESS_MODE}></td>
        </tr>
        <tr>
            <td>
                <label for="ignore_no_lastmod">{TR_IGNORE_NO_LASTMOD}</label>
                <span class="icon i_help" title="{TR_IGNORE_NO_LASTMOD_HELP}">?</span>
            </td>
            <td><input type="checkbox" name="ignore_no_lastmod" id="ignore_no_lastmod"{IGNORE_NO_LASTMOD}></td>
        </tr>
        <tr>
            <td>
                <label for="static_expires">{TR_STATIC_EXPIRES}</label>
                <span class="icon i_help" title="{TR_STATIC_EXPIRES_HELP}">?</span>
            </td>
            <td><input type="checkbox" name="static_expires" id="static_expires"{STATIC_EXPIRES}></td>
        </tr>
        <tr>
            <td>
                <label for="debug_headers">{TR_DEBUG_HEADERS}</label>
                <span class="icon i_help" title="{TR_DEBUG_HEADERS_HELP}">?</span>
            </td>
            <td><input type="checkbox" name="debug_headers" id="debug_headers"{DEBUG_HEADERS}></td>
        </tr>
        <tr>
            <td>
                <label for="default_expire">{TR_DEFAULT_EXPIRE}</label>
                <span class="icon i_help" title="{TR_DEFAULT_EXPIRE_HELP}">?</span>
            </td>
            <td><input type="number" name="default_expire" id="default_expire"
                       min="60" max="604800" value="{DEFAULT_EXPIRE}"></td>
        </tr>
        <tr>
            <td><label for="max_expire">{TR_MAX_EXPIRE}</label></td>
            <td><input type="number" name="max_expire" id="max_expire"
                       min="60" max="604800" value="{MAX_EXPIRE}"></td>
        </tr>
        <tr>
            <td><label for="max_file_size">{TR_MAX_FILE_SIZE}</label></td>
            <td><input type="number" name="max_file_size" id="max_file_size"
                       min="1024" max="104857600" value="{MAX_FILE_SIZE}"></td>
        </tr>
        <tr>
            <td>
                <label for="bypass_cookies">{TR_BYPASS_COOKIES}</label>
                <span class="icon i_help" title="{TR_BYPASS_COOKIES_HELP}">?</span>
            </td>
            <td><textarea name="bypass_cookies" id="bypass_cookies" rows="4" cols="40">{BYPASS_COOKIES}</textarea></td>
        </tr>
        <tr>
            <td>
                <label for="bypass_paths">{TR_BYPASS_PATHS}</label>
                <span class="icon i_help" title="{TR_BYPASS_PATHS_HELP}">?</span>
            </td>
            <td><textarea name="bypass_paths" id="bypass_paths" rows="4" cols="40">{BYPASS_PATHS}</textarea></td>
        </tr>
        </tbody>
    </table>

    <div class="buttons">
        <input name="submit" type="submit" value="{TR_UPDATE}">
        <a class="link_as_button" href="apache_cache.php">{TR_CANCEL}</a>
    </div>
</form>
