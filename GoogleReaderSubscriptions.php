<?php
/*
Plugin Name: Google Reader Subscriptions
Plugin URI: http://github.com/hradtke/wp-google-reader-subcriptions
Description: Display Google Reader subscriptions in sidebar.
Version: 1.0
Author: Herman J. Radtke III
Author URI: http://www.hermanradtke.com
License: MIT
*/

/*
Copyright (C) 2011 by Herman J. Radtke III

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

/*
 * TODO custom cache TTL
 * TODO custom widget title
 */

class GoogleReaderSubscriptions extends WP_Widget 
{
    var $cache_key = 'wp-google-reader-subcriptions';

    function GoogleReaderSubscriptions() 
    {
        load_plugin_textdomain('grsub', false, basename(dirname( __FILE__ )) . '/languages');

        $widget_options = array(
            'description' => __('Display Google Reader subscriptions in sidebar.', 'grsub'), 
            'classname' => 'grsub',
        );

        parent::WP_Widget('grsub', 'Google Reader Subscriptions', $widget_options);
    }

    function get_subscriptions($email, $password)
    {

        $subs = get_transient($this->cache_key);
        if ($subs) {
            return $subs;
        }

        $url = "https://www.google.com/accounts/ClientLogin?service=reader&Email={$email}&Passwd={$password}";

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $output = curl_exec($ch);

        if (curl_errno($ch) !== CURLE_OK) {
            return false;
        }

        curl_close($ch);

        $parts = explode("\n", $output);

        $auth = array();

        foreach ($parts as $part) {
            $tmp = explode('=', $part);

            if ($tmp[0] && $tmp[1]) {
                $auth[$tmp[0]] = $tmp[1];
            }
        }

        if (!isset($auth['Auth'])) {
            return false;
        }

        $headers = array(
            "Authorization: GoogleLogin auth={$auth['Auth']}",
        );

        $ch = curl_init('http://www.google.com/reader/api/0/subscription/list?output=json');

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $subs = curl_exec($ch);

        if (curl_errno($ch) !== CURLE_OK) {
            return false;
        }

        curl_close($ch);


        $subs = json_decode($subs);

        set_transient($this->cache_key, $subs, 60*60*12);

        return $subs;
    }

    function widget($args, $instance) 
    {
        if (!isset($instance['email']) || !isset($instance['password'])) {
            return;
        }

        $email = $instance['email'];
        $password = $this->decrypt_password($instance['password']);

        $subs = $this->get_subscriptions($email, $password);

        if (!$subs) {
            return;
        }

        echo '<h3>Blogroll</h3>';
        echo '<ul class="grsub">';

        foreach ($subs->subscriptions as $sub) {

            $url = esc_attr(substr($sub->id, strlen('feed/')));
            $title = esc_attr($sub->title);

            echo <<<EOT
            <li><a href="{$url}" target="_blank">{$title}</a></li>
EOT;
        }

        echo '</ul>';

    }

    function update($new_instance, $old_instance) 
    {

        if ($new_instance['email'] && $new_instance['password']) {
            $key = constant('NONCE_KEY');

            $password = $this->encrypt_password($new_instance['password']);
            $new_instance['password'] = $password;

            return $new_instance;
        }

        return false;
    }

    function form($instance) 
    {
        $email = esc_attr($instance['email']);

        if ($instance['password']) {
            $password = $this->decrypt_password($instance['password']);
        } else {
            $password = '';
        }
?>
        <label for="<?php echo $this->get_field_id('email'); ?>"><?php _e('Email:', 'grsub'); ?>
        <input class="widefat" id="<?php echo $this->get_field_id('email'); ?>" name="<?php echo $this->get_field_name('email'); ?>" type="text" value="<?php echo $email; ?>" /></label>

        <label for="<?php echo $this->get_field_id('password'); ?>"><?php _e('Password:', 'grsub'); ?>
        <input class="widefat" id="<?php echo $this->get_field_id('password'); ?>" name="<?php echo $this->get_field_name('password'); ?>" type="password" value="<?php echo $password; ?>" /></label>
<?php
    }

    function encrypt_password($password)
    {
        $key = constant('NONCE_KEY');

        $encrypted_password = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key), $password, MCRYPT_MODE_CBC, md5(md5($key))));
        return $encrypted_password;
    }

    function decrypt_password($encrypted_password)
    {
        $key = constant('NONCE_KEY');

        $password = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($key), base64_decode($encrypted_password), MCRYPT_MODE_CBC, md5(md5($key))), "\0");
        return $password;
    }
}

function grsub_register_widget() 
{
    register_widget('GoogleReaderSubscriptions');
}

if (extension_loaded('mcrypt')) {
    add_action('widgets_init', 'grsub_register_widget');
}
