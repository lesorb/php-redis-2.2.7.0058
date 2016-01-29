<?php
// redis master server address
$config['redis']	= array(
						'cluster' => true, //enable [true] when redis version is gt 3.0.
						'timeout' => 10,
						'database' => 0,	//enable when cluster is false.
						'master' => array( '192.168.32.241:7000','192.168.32.242:7000','192.168.32.243:7000' ),
					);