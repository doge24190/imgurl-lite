
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
					<?php if((isset($_COOKIE['user'])) && (isset($_COOKIE['token']))){ ?>
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
	<script src = 'https://libs.xiaoz.top/assets/imgurl.js'></script>
</body>
</html>