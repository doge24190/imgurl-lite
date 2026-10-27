<div class="layui-container site">
	<div class="layui-row">
		<div class="layui-col-lg12">
			<div id="site">
			<form class="layui-form" action = "/set/site" method = "post">
                <?php echo csrf_field(); ?>
                <link rel="stylesheet" href="/static/css/branding.css">
                <fieldset class="layui-elem-field branding-settings">
                    <legend>网站外观</legend>
                    <p class="branding-intro">从已上传图片中选择。保存前会检查原图格式、文件大小和尺寸；如需新照片，请先到首页上传。</p>
                    <?php foreach ([
                        'background' => ['首页背景', '至少 1280 × 720，推荐 1920 × 1080 横向照片；手机端居中裁切。', ''],
                        'logo' => ['网站 Logo', '宽 64–4096、高 32–4096，宽高比 1:4 至 8:1；推荐透明 PNG，显示时保持比例。', '/static/images/logo.png'],
                        'favicon' => ['网站图标', '64–2048 像素的正方形图片，推荐 256 × 256；自动生成 256 × 256 PNG。', '/favicon.ico']
                    ] as $field => [$label, $hint, $default]):
                        $current = branding_url($$field ?? null, $default);
                    ?>
                    <div class="branding-row" data-branding="<?php echo h($field); ?>" data-default="<?php echo h($default); ?>">
                        <div class="branding-preview"><img <?php if ($current !== '') { echo 'src="'.h($current).'"'; } ?> alt="<?php echo h($label); ?>预览" <?php if ($current === '') { echo 'hidden'; } ?>><span <?php if ($current !== '') { echo 'hidden'; } ?>>默认背景</span></div>
                        <div class="branding-details">
                            <strong><?php echo h($label); ?></strong>
                            <p><?php echo h($hint); ?>每张不超过 10 MB，支持 PNG / JPG / WebP / GIF。</p>
                            <input type="hidden" name="<?php echo h($field); ?>_image" value="keep">
                            <button type="button" class="layui-btn layui-btn-sm" data-pick="<?php echo h($field); ?>">从图片库选择</button>
                            <button type="button" class="layui-btn layui-btn-primary layui-btn-sm" data-reset="<?php echo h($field); ?>">恢复默认</button>
                            <p class="branding-selection" aria-live="polite">当前设置；更改后请点击下方“保存”。</p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <p class="branding-intro">外观图片会单独保存副本，删除图片库中的原图不会影响网站外观。</p>
                </fieldset>
				<div class="layui-form-item">
					<label class="layui-form-label">网站标题</label>
					<div class="layui-input-block">
					<input type="text" name="title" required  lay-verify="required" placeholder="请输入网站标题标题" autocomplete="off" class="layui-input" value = "<?php echo h($title ?? ''); ?>">
					</div>
				</div>
				<div class="layui-form-item">
					<label class="layui-form-label">网站关键词</label>
					<div class="layui-input-block">
					<input type="text" name="keywords"  placeholder="多个关键词用英文状态下的逗号(,)分隔" autocomplete="off" class="layui-input" value = "<?php echo h($keywords ?? ''); ?>">
					</div>
				</div>
				<div class="layui-form-item layui-form-text">
					<label class="layui-form-label">站点描述</label>
					<div class="layui-input-block">
<textarea name="description" placeholder="请输入网站描述" class="layui-textarea">
<?php echo h($description ?? ''); ?>
</textarea>
					</div>
				</div>

				<div class="layui-form-item layui-form-text">
					<label class="layui-form-label">统计代码</label>
					<div class="layui-input-block">
						<textarea name="analytics" placeholder="请输入统计代码，目前仅测试过百度统计，其它统计代码可能会出错。" class="layui-textarea"><?php echo h($analytics ?? ''); ?></textarea>
					</div>
				</div>
				<!-- <div class="layui-form-item layui-form-text">
					<label class="layui-form-label">Disqus</label>
					<div class="layui-input-block">
						<textarea name="comments" placeholder="请输入统Disqus评论代码" class="layui-textarea"></textarea>
					</div>
				</div> -->
				<div class="layui-form-item">
					<div class="layui-input-block">
					<button class="layui-btn" lay-submit lay-filter="formsite">保存</button>
					</div>
				</div>
				</form>
			</div>
		</div>
	</div>
</div>

<dialog id="branding-picker" aria-labelledby="branding-picker-title">
    <div class="branding-picker-heading"><h2 id="branding-picker-title">选择已上传图片</h2><button type="button" class="layui-btn layui-btn-primary layui-btn-sm" id="branding-close">关闭</button></div>
    <form id="branding-search"><label for="branding-query">文件名或图片 ID</label><div class="branding-search-controls"><input id="branding-query" class="layui-input" maxlength="200" placeholder="搜索已上传图片"><button class="layui-btn" type="submit">搜索</button></div></form>
    <p id="branding-status" role="status" aria-live="polite"></p>
    <div id="branding-grid"></div>
    <div class="branding-pagination"><button type="button" class="layui-btn layui-btn-primary" id="branding-prev">上一页</button><span id="branding-page"></span><button type="button" class="layui-btn layui-btn-primary" id="branding-next">下一页</button></div>
</dialog>
<script src="/static/js/branding.js"></script>
