<?php

echo "Admin hash:<br />";
echo password_hash('a-long-admin-password', PASSWORD_DEFAULT);
echo "<br />Phone hash:<br />";
echo password_hash('a-separate-phone-password', PASSWORD_DEFAULT);

?>
