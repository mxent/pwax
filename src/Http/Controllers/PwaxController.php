<?php

namespace Mxent\Pwax\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use MatthiasMullie\Minify;
use Mxent\Pwax\PwaxServiceProvider;

class PwaxController extends Controller
{

    public function js($name): Response
    {
        $name = $this->validateViewName($name);
        
        try {
            $viewContents = view($name)->render();
            preg_match('/<script>(.*?)<\/script>/s', $viewContents, $matches);
            $script = isset($matches[1]) ? $matches[1] : '';
            $script = new Minify\JS($script);
            $script = $script->minify();

            return response($script)
                ->header('Content-Type', 'application/javascript')
                ->header('Cache-Control', 'public, max-age=3600');
        } catch (\Exception $e) {
            \Log::error('Error loading script component', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            
            return response('// Error loading script')
                ->header('Content-Type', 'application/javascript')
                ->setStatusCode(500);
        }
    }

    public function css($name): Response
    {
        $name = $this->validateViewName($name);
        
        try {
            $viewContents = view($name)->render();
            preg_match('/<style>(.*?)<\/style>/s', $viewContents, $matches);
            $style = isset($matches[1]) ? $matches[1] : '';
            $style = new Minify\CSS($style);
            $style = $style->minify();

            return response($style)
                ->header('Content-Type', 'text/css')
                ->header('Cache-Control', 'public, max-age=3600');
        } catch (\Exception $e) {
            \Log::error('Error loading stylesheet component', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            
            return response('/* Error loading stylesheet */')
                ->header('Content-Type', 'text/css')
                ->setStatusCode(500);
        }
    }

    public function module($name): JsonResponse|Response
    {
        $name = $this->validateViewName($name);

        return vue($name, null, ['bypass' => true]);
    }

    /**
     * Validate and sanitize the view name to prevent path traversal attacks
     *
     * @param string $name
     * @return string
     * @throws \InvalidArgumentException
     */
    protected function validateViewName(string $name): string
    {
        // Decode the view name format
        $name = str_replace('_x_', '.', str_replace('__x__', '::', $name));
        
        // Validate that the name matches expected format (alphanumeric, dots, colons, hyphens, underscores)
        if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid view name format');
        }

        // Validate that the name doesn't contain path traversal attempts
        if (preg_match('/\.\./', $name) || preg_match('@[\\\\\\/]@', $name)) {
            throw new \InvalidArgumentException('Invalid view name: path traversal detected');
        }
        
        return $name;
    }
}
