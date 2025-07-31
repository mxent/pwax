<!DOCTYPE html>
<html lang="en" dir="ltr">
	<head>
		@include('pwax::includes.head')
		<style>
			.app-not-loaded {
				display: none!important;
			}
			.preloader {
				position: fixed;
				top: 0;
				left: 0;
				width: 100%;
				height: 100%;
				background: #fff;
			}
			.preloader:after {
				content: '';
				position: absolute;
				top: 50%;
				left: 50%;
				width: 60px;
				height: 60px;
				margin: -30px 0 0 -30px;
				border: 6px solid #f3f3f3;
				border-top-color: #0c83ff;
				border-radius: 50%;
				animation: spin 1s linear infinite;
			}
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}
		</style>
	</head>

	<body>
		<div id="app" class="preloader">
			@yield('content')
		</div>

		@include('pwax::includes.foot')

		<script src="{{ url(config('pwax.route_prefix').'/pwax__x__js_x_main.js') }}"></script>
	</body>
</html>
