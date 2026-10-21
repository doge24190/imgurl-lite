<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta charset="utf-8" />
	<title>New Document</title>
	<meta name="generator" content="EverEdit" />
	<meta name="author" content="" />
	<meta name="keywords" content="" />
	<meta name="description" content="" />
	<link rel="stylesheet" href="/static/layui/css/layui.css">
</head>
<body>
	<div class="layui-container">
		<div class="layui-row">
			<div class = "layui-col-lg12">
				<!--后台管理查看图片信息-->
				<table class="layui-table">
                <colgroup>
                    <col width="150">
                    <col>
                </colgroup>
                <tbody>
                    <tr>
                        <td>分辨率</td>
                        <td><?php echo h($width ?? ''); ?> x <?php echo h($height ?? ''); ?></td>
                    </tr>

                    <tr>
                        <td>MIME类型</td>
                        <td><?php echo h($mime ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>扩展名</td>
                        <td><?php echo h($ext ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>上传时间</td>
                        <td><?php echo h($date ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>上传者IP</td>
                        <td><?php echo h($ip ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>浏览次数</td>
                        <td><?php echo h($views ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td>文件大小</td>
                        <td><?php echo h($size ?? ''); ?></td>
                    </tr>
                </tbody>
                </table>
			</div>
		</div>
	</div>
    <script src="/static/layui/layui.js"></script>
</body>
</html>
