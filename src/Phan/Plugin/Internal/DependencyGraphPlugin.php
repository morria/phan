<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal;

use ast\Node;
use Exception;
use Phan\CLI;
use Phan\CodeBase;
use Phan\Exception\FQSENException;
use Phan\Language\Element\Clazz;
use Phan\Language\FileRef;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\PluginV3;
use Phan\PluginV3\AnalyzeClassCapability;
use Phan\PluginV3\FinalizeProcessCapability;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\PluginV3\PostAnalyzeNodeCapability;
use Throwable;

/**
 * This plugin only works correctly with Phan -j1
 * see tool/pdep for an intelligent wrapper for it
 *
 * @phan-file-suppress PhanPluginRemoveDebugAny outputting is deliberate
 */
final class DependencyGraphPlugin extends PluginV3 implements
    AnalyzeClassCapability,
    PostAnalyzeNodeCapability,
    FinalizeProcessCapability
{
    /** @var list<Clazz> */
    private $elements = [];
    /** @var string[] */
    private $class_to_file = [];
    /** @var string[] */
    private $file_to_class = [];
    /** @var array<string,array<string,string>> File-based graph */
    private $fgraph = [];
    /** @var array<string,array<string,string>> Class-based graph */
    private $cgraph = [];
    /** Class types */
    private $ctype = [];
    /** @var int */
    private $depth = 0;

    /**
     * A list of static calls observed by this plugin
     * @var list<array<string,array<string,string>>>
     */
    public static $static_calls = [];
    /**
     * A list of static variable accesses observed by this plugin
     * @var list<array<string,array<string,string>>>
     */
    public static $static_vars = [];

    /**
     * A list of instantiations observed by this plugin
     * @var list<array<string,array<string,string>>>
     */
    public static $instantiations = [];

    /**
     * Build <filename>:<lineno> string
     */
    private static function getFileString(FileRef $file_ref): string
    {
        return $file_ref->getFile() . ':' . $file_ref->getLineNumberStart();
    }

    /**
     * @return array{0:string,1:int}
     */
    private static function getFileLineno(string $file_string): array
    {
        $idx = \strrpos($file_string, ':');
        if ($idx === false) {
            return [$file_string, 0];
        }
        return [\substr($file_string, 0, $idx), (int)\substr($file_string, $idx + 1)];
    }

    /**
     * Build file<->class mappings
     *
     * @unused-param $code_base
     */
    public function analyzeClass(CodeBase $code_base, Clazz $class): void
    {
        $this->elements[] = $class;
        $cnode = (string)$class->getFQSEN()->getCanonicalFQSEN();
        if ($class->isInterface()) {
            $ctype = "I";
        } elseif ($class->isTrait()) {
            $ctype = "T";
        } else {
            $ctype = "C";
        }
        $this->ctype[$cnode] = $ctype;
        $this->class_to_file[$cnode] = self::getFileString($class->getContext());
        $this->file_to_class[self::getFileString($class->getContext())] = $cnode;
    }

    /**
     * Walk the class graph
     *
     * @param string[][] $newgraph
     * @param string $node
     * @return string[][]
     */
    private function walkcGraph(array $newgraph, string $node, int $depth = 0): array
    {
        static $i = 0;
        static $visited = [];

        if ($this->depth && $depth >= $this->depth) {
            return $newgraph;
        }
        if (!empty($visited[$node])) {
            return $newgraph;
        }
        CLI::progress('graph', (++$i) / \count($this->cgraph), $node);
        if (!\array_key_exists($node, $this->cgraph)) {
            if (!\array_key_exists('\\' . $node, $this->cgraph)) {
                return $newgraph;
            } else {
                $node = '\\' . $node;
            }
        }
        $newgraph[$node] = $this->cgraph[$node];
        $visited[$node] = true;
        foreach ($this->cgraph[$node] as $n => $unused) {
            $newgraph = $this->walkcGraph($newgraph, $n, $depth + 1);
        }
        return $newgraph;
    }

    /**
     * Walk the file graph
     *
     * @param string[][] $newgraph
     * @param string $node
     * @return string[][]
     */
    private function walkfGraph(array $newgraph, string $node, int $depth = 0): array
    {
        static $i = 0;
        static $visited = [];

        if ($this->depth && $depth >= $this->depth) {
            return $newgraph;
        }
        if (!empty($visited[$node])) {
            return $newgraph;
        }
        CLI::progress('graph', (++$i) / \count($this->cgraph), $node);
        if (!\array_key_exists($node, $this->fgraph)) {
            return $newgraph;
        }
        $newgraph[$node] = $this->fgraph[$node];
        $visited[$node] = true;
        foreach ($this->fgraph[$node] as $n => $unused) {
            $newgraph = $this->walkfGraph($newgraph, $n, $depth + 1);
        }
        return $newgraph;
    }

    /**
     * Print a graph for debugging purposes
     *
     * @param string[][] $graph
     */
    private function printGraph(array $graph): void
    {
        foreach ($graph as $k => $v) {
            echo "$k\n";
            foreach ($v as $kk => $vv) {
                echo "\t$kk";
                [$t,$lineno] = self::getFileLineno($vv);
                switch ($t) {
                    case 'C':
                        $type = 'Inheritance';
                        break;
                    case 'I':
                        $type = 'Interface';
                        break;
                    case 'T':
                        $type = 'Trait';
                        break;
                    case 's':
                        $type = 'Static call';
                        break;
                    case 'v':
                        $type = 'Static class variable';
                        break;
                    default:
                        $type = "unknown ($t)";
                        break;
                }
                if (\strstr((string)$kk, '.')) {
                    echo ":$lineno $type\n";
                } else {
                    if (!\array_key_exists($kk, $this->class_to_file)) {
                        continue;
                    }
                    $file = self::getFileLineno($this->class_to_file[$kk])[0];
                    echo " - $file:$lineno $type\n";
                }
            }
        }
    }

    /**
     * Build the actual class and file graphs
     *
     * @unused-param $code_base
     */
    public function finalizeProcess(CodeBase $code_base): void
    {
        if (empty($this->elements)) {
            \fwrite(\STDERR, "Nothing to analyze - please run pdep from your top-level project directory" . \PHP_EOL);
            exit(\EXIT_FAILURE);
        }
        // Loop through all the elements and pull out the reference list for each
        foreach ($this->elements as $element) {
            $fnode = $element->getFileRef()->getFile();

            $cnode = (string)$element->getFQSEN()->getCanonicalFQSEN();
            if ($element->isInterface()) {
                $ctype = "I";
            } elseif ($element->isTrait()) {
                $ctype = "T";
            } else {
                $ctype = "C";
            }

            if (empty($this->fgraph[$fnode])) {
                $this->fgraph[$fnode] = [];
            }
            if (empty($this->cgraph[$cnode])) {
                $this->cgraph[$cnode] = [];
            }
            $refs = $element->getReferenceList();
            foreach ($refs as $ref) {
                $dep_node = $ref->getFile();
                if (empty($this->file_to_class[self::getFileString($ref)])) {
                    continue;
                }
                $cdep_node = $this->file_to_class[self::getFileString($ref)];
                $this->fgraph[$fnode][$dep_node] = $ctype . ':' . $ref->getLineNumberStart();
                $this->cgraph[$cnode][$cdep_node] = $ctype . ':' . $ref->getLineNumberStart();
            }
        }

        $flags = (int)$_ENV['PDEP_GRAPH_FLAGS'];

        if (!($flags & \PDEP_IGNORE_STATIC)) {
            foreach (self::$static_calls as $c) {
                $cnode = \key($c);
                if ($cnode === null) {
                    continue;
                }
                if (!\array_key_exists($cnode, $this->class_to_file)) {
                    continue;
                }
                $fnode = self::getFileLineno($this->class_to_file[$cnode])[0];
                $this->fgraph[$fnode][$c[$cnode]['file']]  = 's:' . $c[$cnode]['lineno'];
                $this->cgraph[$cnode][$c[$cnode]['class']] = 's:' . $c[$cnode]['lineno'];
            }
            foreach (self::$static_vars as $c) {
                $cnode = \key($c);
                if ($cnode === null) {
                    continue;
                }
                if (!\array_key_exists($cnode, $this->class_to_file)) {
                    continue;
                }
                $fnode = self::getFileLineno($this->class_to_file["$cnode"])[0];
                $this->fgraph[$fnode][$c[$cnode]['file']]  = 'v:' . $c[$cnode]['lineno'];
                $this->cgraph[$cnode][$c[$cnode]['class']] = 'v:' . $c[$cnode]['lineno'];
            }
        }

        if (!($flags & \PDEP_IGNORE_NEW)) {
            foreach (self::$instantiations as $c) {
                $cnode = \key($c);
                if ($cnode === null) {
                    continue;
                }
                if (!\array_key_exists($cnode, $this->class_to_file)) {
                    continue;
                }
                $fnode = self::getFileLineno($this->class_to_file["$cnode"])[0];
                $this->fgraph[$fnode][$c[$cnode]['file']]  = 'i:' . $c[$cnode]['lineno'];
                $this->cgraph[$cnode][$c[$cnode]['class']] = 'i:' . $c[$cnode]['lineno'];
            }
        }

        $this->processGraph();
    }

    /**
     * Process either the cached or generated graph
     * @param ?array<string,mixed> $cached_graph
     */
    public function processGraph(?array $cached_graph = null): void
    {
        $cmd  = $_ENV['PDEP_CMD'];
        $mode = $_ENV['PDEP_MODE'];
        $this->depth = (int)$_ENV['PDEP_DEPTH'];
        $args = empty($_ENV['PDEP_ARGS']) ? null : \explode(' ', $_ENV['PDEP_ARGS']);
        $flags = (int)$_ENV['PDEP_GRAPH_FLAGS'];
        $graph = [];

        if ($cached_graph) {
            $this->ctype = $cached_graph['ctype'];
            $this->class_to_file = $cached_graph['class_to_file'];
            $this->file_to_class = $cached_graph['file_to_class'];
            $this->fgraph = $cached_graph['fgraph'];
            $this->cgraph = $cached_graph['cgraph'];
        }

        if (empty($args)) {
            if ($mode === 'class') {
                $graph = $this->cgraph;
            } elseif ($mode === 'file') {
                $graph = $this->fgraph;
            }
        } elseif ($cmd !== 'json' || $this->depth !== 0) {
            foreach ($args as $v) {
                if (empty($v)) {
                    continue;
                }
                if ($mode === 'class') {
                    if (\strstr($v, '.')) {
                        if (!\array_key_exists($v, $this->file_to_class)) {
                            // Probably no lineno specified, do a linear search
                            foreach ($this->file_to_class as $fi => $cl) {
                                $file = self::getFileLineno((string)$fi)[0];
                                if ($file == $v) {
                                    $v = $cl;
                                    goto cfound;
                                }
                            }
                            \fwrite(\STDERR, "Couldn't find file $v" . \PHP_EOL);
                            exit(\EXIT_FAILURE);
                        }
                        $v = $this->file_to_class[$v];
                    }

                    try {
                        $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($v);
                    } catch (FQSENException $e) {
                        \fwrite(\STDERR, "Invalid class fqsen $v: {$e->getMessage()}\n");
                        exit(\EXIT_FAILURE);
                    }
                    $cnode = (string)$fqsen;
                    if (!\array_key_exists($cnode, $this->class_to_file)) {
                        \fwrite(\STDERR, "Couldn't find class $cnode" . \PHP_EOL);
                        exit(\EXIT_FAILURE);
                    }
                    cfound:
                    $graph = $this->walkcGraph($graph, $v);
                } elseif ($mode === 'file') {
                    if (!\strstr($v, '.')) {
                        try {
                            $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($v);
                        } catch (FQSENException $e) {
                            \fwrite(\STDERR, "Invalid class fqsen $v: {$e->getMessage()}\n");
                            exit(\EXIT_FAILURE);
                        }
                        $cnode = (string)$fqsen;
                        if (!\array_key_exists($cnode, $this->class_to_file)) {
                            \fwrite(\STDERR, "Couldn't find class $cnode" . \PHP_EOL);
                            exit(\EXIT_FAILURE);
                        }
                        $v = self::getFileLineno($this->class_to_file[$cnode])[0];
                    }
                    if (!\array_key_exists($v, $this->file_to_class)) {
                        // Probably no lineno specified, do a linear search
                        foreach ($this->file_to_class as $fi => $cl) {
                            $file = self::getFileLineno((string)$fi)[0];
                            if ($file == $v) {
                                $v = $file;
                                goto ffound;
                            }
                        }
                        \fwrite(\STDERR, "Couldn't find file $v" . \PHP_EOL);
                        exit(\EXIT_FAILURE);
                    }
                    ffound:
                    $graph = $this->walkfGraph($graph, $v);
                }
            }
        }
        if (CLI::shouldShowProgress()) {
            // Don't overlap stdout with the progress bar on stderr.
            \fwrite(\STDERR, "\n");
        }
        if ($cached_graph) {
            $ignore_static = $flags & \PDEP_IGNORE_STATIC;
            $ignore_new = $flags & \PDEP_IGNORE_NEW;
            if ($ignore_static || $ignore_new) {
                foreach ($graph as $node => $els) {
                    foreach ($els as $el => $val) {
                        $s = \substr((string)$val, 0, 2);
                        if ($ignore_static && ($s === 'v:' || $s  === 's:')) {
                            unset($graph[$node][$el]);
                        }
                        if ($ignore_new && $s === 'i:') {
                            unset($graph[$node][$el]);
                        }
                    }
                }
            }
        }
        if ($cmd === 'graph') {
            ($mode === 'class') ? $this->dumpClassDot(\basename((string)\getcwd()), $graph) : $this->dumpFileDot(\basename((string)\getcwd()), $graph);
        } elseif ($cmd === 'graphml') {
            $this->dumpGraphML(\basename((string)\getcwd()), $graph, $mode === 'class', (bool)($flags & \PDEP_HIDE_LABELS));
        } elseif ($cmd === 'json' && $this->depth === 0) {
            echo \json_encode([
                'cgraph' => $this->cgraph,
                'fgraph' => $this->fgraph,
                'ctype' => $this->ctype,
                'file_to_class' => $this->file_to_class,
                'class_to_file' => $this->class_to_file
            ]);
        } elseif ($cmd === 'json' && $this->depth !== 0) {
            echo \json_encode($graph, \JSON_PRETTY_PRINT);
        } else {
            $this->printGraph($graph);
        }
        exit(\EXIT_SUCCESS);
    }

    /**
     * Dump the graphviz dot output for the file graph
     * @param string $title
     * @param ?array<string,array<string,string>> $graph
     */
    private function dumpFileDot(string $title, ?array $graph = null): void
    {
        if (!$graph) {
            $graph = $this->fgraph;
        }
        $shapes = '';
        $shape_defined = [];
        echo "strict digraph $title {\nrankdir=RL\nsplines=ortho\n";
        foreach ($graph as $node => $dep_node) {
            if (empty($shape_defined[$node])) {
                $shapes .= "\"$node\" [shape=box]\n";
                $shape_defined[$node] = true;
            }
            foreach ($dep_node as $dnode => $val) {
                [$type,$lineno] = self::getFileLineno((string)$val);
                $style = '';
                if ($type === 's') {
                    $style = ',color="#E66100"';
                }
                if ($type === 'v') {
                    $style = ',color="#5D3A9B",style=dashed';
                }
                if ($type === 'i') {
                    $style = ',color="#008080"';
                }
                echo "\"$dnode\" -> \"$node\" [taillabel=$lineno,labelfontsize=10,labeldistance=1.4{$style}]\n";
                if (empty($shape_defined[$dnode])) {
                    $shapes .= "\"$dnode\" [shape=box]\n";
                    $shape_defined[$dnode] = true;
                }
            }
        }
        echo $shapes;
        echo "}\n";
    }

    /**
     * Dump the graphviz dot output for the class graph
     * @param string $title
     * @param ?array<string,array<string,string>> $graph
     */
    private function dumpClassDot(string $title, ?array $graph = null): void
    {
        if (!$graph) {
            $graph = $this->cgraph;
        }
        echo "strict digraph $title {\nrankdir=RL\nsplines=ortho\n";
        $shapes = '';
        $shape_defined = [];
        foreach ($graph as $node => $dep_node) {
            if (empty($shape_defined[$node])) {
                $shape = "";
                switch ($this->ctype[$node]) {
                    case 'C':
                        $shape = "shape=box";
                        break;
                    case 'I':
                        $shape = "shape=note,color=blue,fontcolor=blue";
                        break;
                    case 'T':
                        $shape = 'shape=component,color="#4B0092",fontcolor="#4B0092"';
                        break;
                }

                if ($shape) {
                    // Defer the shape definitions until after the edges. This tends to give a better node layout
                    $shapes .= '"' . \addslashes(\trim($node, "\\")) . "\" [$shape]\n";
                    $shape_defined[$node] = true;
                }
            }
            foreach ($dep_node as $dnode => $val) {
                [$dnode] = \explode(',', (string)$dnode);
                $type = self::getFileLineno((string)$val)[0];
                $style = '';
                if ($type === 's') {
                    $style = ' [color="#E66100"]';
                } elseif ($type === 'v') {
                    $style = ' [color="#5D3A9B",style=dashed]';
                }
                echo '"' . \addslashes(\trim($dnode, "\\")) . '" -> "' . \addslashes(\trim($node, "\\")) . "\"$style\n";
                if (empty($shape_defined[$dnode])) {
                    $shape = "";
                    switch ($this->ctype[$dnode]) {
                        case 'C':
                            $shape = "shape=box";
                            break;
                        case 'I':
                            $shape = "shape=note,color=blue,fontcolor=blue";
                            break;
                        case 'T':
                            $shape = 'shape=component,color="#4B0092",fontcolor="#4B0092"';
                            break;
                    }

                    if ($shape) {
                        // Defer the shape definitions until after the edges. This tends to give a better node layout
                        $shapes .= '"' . \addslashes(\trim($dnode, "\\")) . "\" [$shape]\n";
                        $shape_defined[$dnode] = true;
                    }
                }
            }
        }
        echo $shapes;
        echo "}\n";
    }

    /**
     * Dump the class or file graph in GraphML format
     * @param string $title
     * @param array<string,array<string,string>> $graph
     * @param bool $is_classgraph
     * @param bool $hide_labels
     */
    private function dumpGraphML(string $title, array $graph, bool $is_classgraph, bool $hide_labels): void
    {
        $node_id = 0;
        $edge_id = 0;

        if ($hide_labels) {
            $visible = 'false';
        } else {
            $visible = 'true';
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . \PHP_EOL;
        echo '<graphml xmlns="http://graphml.graphdrawing.org/xmlns"' . \PHP_EOL;
        echo '         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . \PHP_EOL;
        echo '         xmlns:y="http://www.yworks.com/xml/graphml"' . \PHP_EOL;
        echo '         xmlns:java="http://www.yworks.com/xml/yfiles-common/1.0/java"' . \PHP_EOL;
        echo '         xmlns:sys="http://www.yworks.com/xml/yfiles-common/markup/primitives/2.0"' . \PHP_EOL;
        echo '         xmlns:x="http://www.yworks.com/xml/yfiles-common/markup/2.0"' . \PHP_EOL;
        echo '         xmlns:yed="http://www.yworks.com/xml/yed/3"' . \PHP_EOL;
        echo '         xsi:schemaLocation="http://graphml.graphdrawing.org/xmlns' . \PHP_EOL;
        echo '         http://graphml.graphdrawing.org/xmlns/1.0/graphml.xsd">' . \PHP_EOL;
        echo '  <key attr.name="Title" attr.type="string" for="graph" id="d0"/>' . \PHP_EOL;
        echo '  <key id="d1" for="node" attr.name="color" attr.type="string">' . \PHP_EOL;
        echo '    <default>black</default>' . \PHP_EOL;
        echo '  </key>' . \PHP_EOL;
        echo '  <key id="d2" for="node" attr.name="ntype" attr.type="string">' . \PHP_EOL;
        echo '    <default>class</default>' . \PHP_EOL;
        echo '  </key>' . \PHP_EOL;
        echo '  <key id="d3" for="edge" attr.name="color" attr.type="string">' . \PHP_EOL;
        echo '    <default>black</default>' . \PHP_EOL;
        echo '  </key>' . \PHP_EOL;
        echo '  <key id="d4" for="edge" attr.name="lineno" attr.type="int"/>' . \PHP_EOL;
        echo '  <key id="d5" for="node" yfiles.type="nodegraphics"/>' . \PHP_EOL;
        echo '  <key id="d6" for="edge" yfiles.type="edgegraphics"/>' . \PHP_EOL;
        echo '  <graph id="G" edgedefault="directed">' . \PHP_EOL;
        echo '    <data key="d0" xml:space="preserve"><![CDATA[' . $title . ']]></data>' . \PHP_EOL;

        // Build node_id map
        $nodes = [];
        foreach (\array_keys($graph) as $node) {
            $node_name = \trim($node, "\\");
            $nodes[$node_name] = $node_id++;
        }
        $node_id = 0;

        // Nodes
        foreach ($graph as $node => $dep_node) {
            $node_name = \trim($node, "\\");
            $col = '#FFFFFF';
            $ntype = 'class';
            $shape = 'rectangle';
            echo '    <node id="n' . $node_id . '">' . \PHP_EOL;
            if ($is_classgraph) {
                switch ($this->ctype[$node]) {
                    case 'I':
                        $col = '#6699FF';
                        $ntype = 'interface';
                        $shape = 'ellipse';
                        break;
                    case 'T':
                        $col = '#C483D1';
                        $ntype = 'trait';
                        $shape = 'hexagon';
                        break;
                }
                echo '      <data key="d1">' . $col . '</data>' . \PHP_EOL;
                echo '      <data key="d2">' . $ntype . '</data>' . \PHP_EOL;
            }
            echo '      <data key="d5">' . \PHP_EOL;
            echo '        <y:ShapeNode>' . \PHP_EOL;
            echo '          <y:Geometry height="30.0" width="160.0"/>' . \PHP_EOL;
            echo '          <y:Fill color="' . $col . '" transparent="false"/>' . \PHP_EOL;
            echo '          <y:NodeLabel alignment="center" visible="' . $visible . '">' . $node_name . '</y:NodeLabel>' . \PHP_EOL;
            echo '          <y:Shape type="' . $shape . '"/>' . \PHP_EOL;
            echo '        </y:ShapeNode>' . \PHP_EOL;
            echo '      </data>' . \PHP_EOL;
            echo '    </node>' . \PHP_EOL;

            // Edges
            foreach ($dep_node as $dnode => $val) {
                $ecol = '#000000';
                [$type,$lineno] = self::getFileLineno((string)$val);
                [$dnode] = \explode(',', $dnode);
                if ($type === 's') {
                    $ecol = '#E66100'; // Orange
                } elseif ($type === 'v') {
                    $ecol = '#5D3A9B';  // Purple
                }
                $source = $nodes[\trim($dnode, "\\")];
                $target = $nodes[\trim($node, "\\")];
                echo '    <edge id="e' . $edge_id . '" source="n' . $source . '" target="n' . $target . '">' . \PHP_EOL;
                echo '      <data key="d3">' . $ecol . '</data>' . \PHP_EOL;
                if (!$is_classgraph) {
                    echo '      <data key="d4">' . $lineno . '</data>' . \PHP_EOL;
                }
                echo '      <data key="d6">' . \PHP_EOL;
                echo '        <y:PolyLineEdge>' . \PHP_EOL;
                echo '          <y:LineStyle color="' . $ecol . '" type="line" width="1.0"/>' . \PHP_EOL;
                echo '          <y:Arrows source="none" target="standard"/>' . \PHP_EOL;
                if (!$is_classgraph) {
                    echo '          <y:EdgeLabel alignment="center" textColor="#000000" visible="' . $visible . '">' . $lineno . '</y:EdgeLabel>' . \PHP_EOL;
                }
                echo '        </y:PolyLineEdge>' . \PHP_EOL;
                echo '      </data>' . \PHP_EOL;

                echo '    </edge>' . \PHP_EOL;
                $edge_id++;
            }
            $node_id++;
        }
        echo '  </graph>' . \PHP_EOL;
        echo '</graphml>' . \PHP_EOL;
    }

    /** Hook up our visitor class */
    public static function getPostAnalyzeNodeVisitorClassName(): string
    {
        return DependencyGraphVisitor::class;
    }
}

/**
 * Our visitor methods live here
 */
class DependencyGraphVisitor extends PluginAwarePostAnalysisVisitor
{
    /**
     * When we hit an AST_CLASS_CONST
     * @throws Exception
     */
    public function visitClassConst(Node $node): void
    {
        $context = $this->context;
        try {
            $class_fqsen = $context->getClassFQSEN();
        } catch (Throwable $unused_e) {
            return;
        }
        if ($context->isInGlobalScope()) {
            return;
        }
        $called_class = $node->children['class'];
        if (!isset($called_class->children['name'])) {
            return;
        }
        $called_class_name = (string)$called_class->children['name'];
        // None of these add any dependency data we don't already have, so ignore them
        if (\in_array(\strtolower($called_class_name), ['self', 'parent', 'class', 'static'], true)) {
            return;
        }
        $fqsen = (string)FullyQualifiedClassName::fromStringInContext($called_class_name, $context);
        DependencyGraphPlugin::$static_vars[] = [$fqsen => ['class' => (string)$class_fqsen,'file' => $context->getFile(),'lineno' => $context->getLineNumberStart()]];
    }

    /**
     * When we hit an AST_STATIC_CALL
     * @throws Exception
     */
    public function visitStaticCall(Node $node): void
    {
        $context = $this->context;
        try {
            $class_fqsen = $context->getClassFQSEN();
        } catch (Throwable $unused_e) {
            return;
        }
        if ($context->isInGlobalScope()) {
            return;
        }
        $called_class = $node->children['class'];
        if (!isset($called_class->children['name'])) {
            return;
        }
        $called_class_name = (string)$called_class->children['name'];
        if (\in_array(\strtolower($called_class_name), ['self', 'parent', 'class', 'static'], true)) {
            return;
        }
        $fqsen = (string)FullyQualifiedClassName::fromStringInContext($called_class_name, $context);
        DependencyGraphPlugin::$static_calls[] = [$fqsen => ['class' => (string)$class_fqsen,'file' => $context->getFile(),'lineno' => $context->getLineNumberStart()]];
    }

    /**
     * When we hit an AST_NEW
     * @throws Exception
     */
    public function visitNew(Node $node): void
    {
        $context = $this->context;
        try {
            $class_fqsen = $context->getClassFQSEN();
        } catch (Throwable $unused_e) {
            return;
        }
        if ($context->isInGlobalScope()) {
            return;
        }
        $called_class = $node->children['class'];
        if (!isset($called_class->children['name'])) {
            return;
        }
        $called_class_name = (string)$called_class->children['name'];
        // None of these add any dependency data we don't already have, so ignore them
        if (\in_array(\strtolower($called_class_name), ['self', 'parent', 'class', 'static'], true)) {
            return;
        }
        $fqsen = (string)FullyQualifiedClassName::fromStringInContext($called_class_name, $context);
        DependencyGraphPlugin::$instantiations[] = [$fqsen => ['class' => (string)$class_fqsen,'file' => $context->getFile(),'lineno' => $context->getLineNumberStart()]];
    }
}

return new DependencyGraphPlugin();
