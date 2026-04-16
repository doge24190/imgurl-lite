
<?php
    //获取版本号
    $ver_file = FCPATH.'data/version.txt';
    if(is_file($ver_file)){
        @$version = file_get_contents($ver_file);
    }
?>
	<!-- 底部 -->
	<div class = "footer">
		<div class = "layui-container">
			<div class = "layui-row">
				<div class = "layui-col-lg12">
					Copyright © 2017-2016 Powered by <a href="https://imgurl.org/" target = "_blank" title = "ImgURL是一个开源免费的图床程序">ImgURL</a> | Edited by <a href="https://www.doge24190.top/" target = "_blank" title = "狗窝">doge24190.top</a> | 
					<!-- 简单判断用户是否登录 -->
					<?php
					$CI =& get_instance();
					$CI->load->library('basic');
					$is_login = $CI->basic->is_login(FALSE);
					?>

					<?php if($is_login){ ?>
						<a href="/user/logout">logout</a>
					<?php }else{ ?>
						<a href="/user/login">login</a>
					<?php } ?>
					<!-- 简单判断用户是否登录END -->
				</div>
			</div>
		</div>
	</div>
	<!-- 底部END -->
	<script src="/static/layui/layui.js"></script>
	<script src="/static/embed.js?v=<?php echo $version; ?>"></script>
	<script src="/static/clipBoard.min.js?v=1.40"></script>
</body>
</html>