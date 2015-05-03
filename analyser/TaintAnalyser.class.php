<?php
/**
 * 用于污点分析的类
 * 污点分析的任务：
 * 	（1）从各个基本块摘要中找出危险参数的变化
 * 	（2）评估危险参数是否受到有效净化
 * 	（3）根据评估结果报告漏洞
 * @author Exploit
 *
 */
class TaintAnalyser {
	//方法getPrevBlocks返回的参数
	private $pathArr = array() ;
	//source数组（GET POST ...）
	private $sourcesArr = array() ;
	
	public function __construct(){
		$this->sourcesArr = Sources::getUserInput() ;
	}
	
	public function getPathArr() {
		return $this->pathArr;
	}
	
	/**
	 * 根据变量的节点返回变量的名称
	 * @param unknown $var
	 * @return Ambigous <base, NULL, string, unknown>
	 */
	public function getVarName($var){
		$types = array('ArrayDimFetchSymbol','ConstantSymbol','ValueSymbol','VariableSymbol') ;
		if(in_array(get_class($var), $types)){
			$varName = NodeUtils::getNodeStringName($var->getValue()) ;
		}else{
			$varName = NodeUtils::getNodeStringName($var) ;
		}
		return $varName ;
	}
	
	/**
	 * 根据变量列表，判断某个元素的类型
	 * 如：
	 * (1)$sql = "select * from user where uid=".$uid ;
	 * 那么uid为数值类型
	 * (2)$sql = "select * from user where uid='".$uid ."'" ;
	 * 那么uid为字符类型
	 * @param array $vars
	 */
	public function addTypeByVars(&$vars){
		$len = count($vars) ;
		//调整顺序
		if($len > 2){
			$item_1 = $vars[0] ;
			$item_2 = $vars[1] ;
			$vars[0] = $item_2 ;
			$vars[1] = $item_1 ;
			unset($item_1) ;
			unset($item_2) ;
		}

		//设置type
		for($i=0;$i<$len;$i++){
			//如果元素有前驱和后继
			if(($i - 1) >= 0 && ($i + 1) <= $len-1){
				$is_pre_value = $vars[$i-1] instanceof ValueSymbol ;
				$is_curr_var = !($vars[$i] instanceof ValueSymbol) ;
				$is_nex_value = $vars[$i+1] instanceof ValueSymbol ;
				
				//如果前驱后继都不是value类型或者当前symbol不是变量，则pass
				if(!$is_pre_value || !$is_nex_value || !$is_curr_var){
					continue ;
				}
				//判断是否被单引号包裹
				$is_start_with = strpos($vars[$i-1]->getValue(),"'",0) ;
				$is_end_with = strpos($vars[$i+1]->getValue(),"'",strlen($vars[$i-1]->getValue())-2) ;
				
				if($is_start_with != -1 && $is_end_with != -1){
					$vars[$i]->setType("int") ;
				}
			}
		}
	}
	
	
	/**
	 * 根据flow获取数据流中赋值的变量名列表
	 * @param unknown $flow
	 * @return multitype:NULL
	 */
	public function getVarsByFlow($flow){
		//获取flow中的变量数组
		if($flow->getValue() instanceof ConcatSymbol){
			$vars = $flow->getValue()->getItems();
		}else{
			$vars = array($flow->getValue()) ;
		}
		
		//设置var的type，如果有引号包裹为string，否则为数值型
		$this->addTypeByVars($vars) ;
		print_r($vars) ;
		return $vars ;
	}
	
	
	/**
	 * 获取当前基本块的所有前驱基本块
	 * @param BasicBlock $block
	 * @return Array 返回前驱基本块集合$this->pathArr
	 * 使用该方法时，需要对类属性$this->pathArr进行初始化
	 */
	public function getPrevBlocks($currBlock){
		if($currBlock != null){
			$blocks = array() ;
			$edges = $currBlock->getInEdges();
			
			//如果到达了第一个基本块则返回
			if(!$edges) return $this->pathArr;
			
			foreach ($edges as $edge){
				array_push($blocks, $edge->getSource()) ;
			}
			
			if(count($blocks) == 1){
				//前驱的节点只有一个
				if(!in_array($blocks[0],$this->pathArr)){
					array_push($this->pathArr,$blocks[0]) ;
				} 
			}else{
				//前驱节点有多个
				if(!in_array($blocks,$this->pathArr)){
					array_push($this->pathArr,$blocks) ;
				} 
			}
		
			//递归
			foreach($blocks as $bitem){
				if(!is_array($bitem)){
					$this->getPrevBlocks($bitem);
				}else{
					$this->getPrevBlocks($bitem[0]) ;
				}
				
			}
		
		}
	}
	
	
	/**
	 * 污点分析中，对当前基本块的探测
	 * @param BasicBlock $block  当前基本块
	 * @param Node $node  当前调用sink的node
	 * @param string $argName  危险参数的名称
	 * @param int $flowsNum 数据流的数量，用于清除分析的flow
	 */
	public function currBlockTaintHandler($block,$node,$argName,$flowsNum=0){
		//获取数据流信息
		$flows = $block->getBlockSummary() ->getDataFlowMap() ;
		$flows = array_reverse($flows); //逆序处理flows

		foreach ($flows as $flow){
			if($flow->getName() == $argName){
				//处理净化信息,如果被编码或者净化则返回safe
				//被isSanitization函数取代
				if ($flow && $flow->getLocation()->getSanitization()){
					return "safe";
				}
				
				//获取flow中的右边赋值变量
				//得到flow->getValue()的变量node
				//$sql = $a . $b ;  =>  array($a,$b)
				$vars = $this->getVarsByFlow($flow) ;
				//print_r($vars) ;
				$retarr = array();
				foreach($vars as $var){
					$varName = $this->getVarName($var) ;
					
					//如果var右边有source项
					if(in_array($varName, $this->sourcesArr)){
						//报告漏洞
						$this->report($node, $flow->getLocation()) ;
						return true ;
					}
					
					$ret = $this->currBlockTaintHandler($block, $node, $varName,$flowsNum) ;
					//变量经过净化，这不需要跟踪该变量
					
					
				}
			}
		}
	}
	
	
	/**
	 * 处理多个block的情景
	 * @param BasicBlock $block 当前基本块
	 * @param string $argName 敏感参数名
	 * @param Node $node 调用sink的node 
	 */
	public function multiBlockHandler($block,$argName,$node,$flowsNum=0){
		if($this->pathArr){
			$this->pathArr = array() ;
		}
		
		$this->getPrevBlocks($block) ;
		$block_list = $this->pathArr ;
		//array_push($block_list, $block) ;
		
		//如果前驱基本块为空，说明完成回溯，算法停止
		if($block_list == null || count($block_list) == 0){
			return  ;
		}
		
		//处理非平行结构的前驱基本块
		if(!is_array($block_list[0])){
			$flows = $block_list[0]->getBlockSummary()->getDataFlowMap() ;
			$flows = array_reverse($flows) ;
			
			//如果flow中没有信息，则换下一个基本块
			if($flows == null){
				//找到新的argName
				foreach ($block->getBlockSummary()->getDataFlowMap() as $flow){
					if($flow->getName() == $argName){
						$vars = $this->getVarsByFlow($flow) ;
						foreach ($vars as $var){
							$varName = $this->getVarName($var) ;
							$ret = $this->multiBlockHandler($block_list[0], $varName, $node) ;
						}
					}else{
						//在最初block中，argName没有变化则直接递归
						$this->multiBlockHandler($block_list[0], $argName, $node) ;
						array_shift($block_list) ;
					}
					
				}

			}else{
				//对于每个flow,寻找变量argName
				foreach ($flows as $flow){
					if($flow->getName() == $argName){
						//处理净化信息,如果被编码或者净化则返回safe
						//被isSanitization函数取代
						if ($flow && $flow->getLocation()->getSanitization()){
							return "safe";
						}
				
						//获取flow中的右边赋值变量
						//得到flow->getValue()的变量node
						//$sql = $a . $b ;  =>  array($a,$b)
						$vars = $this->getVarsByFlow($flow) ;
				
						$retarr = array();
				
						foreach($vars as $var){
							$varName = $this->getVarName($var) ;
							
							//如果var右边有source项
							if(in_array($varName, $this->sourcesArr)){
								//报告漏洞
								$this->report($node, $flow->getLocation()) ;
								return true ;
							}else{
								$ret = $this->multiBlockHandler($block_list[0], $varName, $node,$flowsNum) ;
								array_shift($block_list) ;
							}
							
						}
				
					}
				}
			}
			
			
			
		}else if(is_array($block_list[0]) && count($block_list) > 0){
			//是平行结构
			foreach ($block_list[0] as $block_item){
				$flows = $block_item->getBlockSummary()->getDataFlowMap() ;
				$flows = array_reverse($flows) ;

				//如果flow中没有信息，则换下一个基本块
				if($flows == null){
					//找到新的argName
					foreach ($block->getBlockSummary()->getDataFlowMap() as $flow){
						if($flow->getName() == $argName){
							$vars = $this->getVarsByFlow($flow) ;
							foreach ($vars as $var){
								$varName = $this->getVarName($var) ;
								$ret = $this->multiBlockHandler($block_item, $varName, $node) ;
								
								if(count($block_list[0]) == 0){
									array_shift($block_list) ;
								}
								
							}
						}else{
							//在最初block中，argName没有变化则直接递归
							$this->multiBlockHandler($block_item, $argName, $node) ;
							array_shift($block_list) ;
						}
						
					}
					
				}else{
					//对于每个flow,寻找变量argName
					foreach ($flows as $flow){
						if($flow->getName() == $argName){
							//处理净化信息,如果被编码或者净化则返回safe
							//被isSanitization函数取代
							if ($flow && $flow->getLocation()->getSanitization()){
								return "safe";
							}
								
							//获取flow中的右边赋值变量
							//得到flow->getValue()的变量node
							//$sql = $a . $b ;  =>  array($a,$b)
							$vars = $this->getVarsByFlow($flow) ;
					
							$retarr = array();
							foreach($vars as $var){
								$varName = $this->getVarName($var) ;
								//如果var右边有source项,直接报告漏洞
								if(in_array($varName, $this->sourcesArr)){
									//报告漏洞
									$this->report($node, $flow->getLocation()) ;
									return true ;
								}else{
									$ret = $this->multiBlockHandler($block_item, $varName, $node,$flowsNum) ;
									
									if(count($block_list[0]) == 0){
										array_shift($block_list) ;
									}
								}
									
							}
								
						}
					}
				}
				
			}
		}
		
	}
	
	/**
	 * 根据sink的类型、危险参数的净化信息列表、编码列表
	 * 判断是否是有效的净化
	 * 返回true or false
	 * 'XSS','SQLI','HTTP','CODE','EXEC','LDAP','INCLUDE','FILE','XPATH','FILEAFFECT'
	 * @param string $type 漏洞的类型，使用TypeUtils可以获取
	 * @param array $saniArr 危险参数的净化信息栈
	 * @param array $encodingArr 危险参数的编码信息栈
	 */
	public function isSanitization($type,$saniArr,$encodingArr){
		switch ($type){
			case 'SQLI':
				$sql_analyser = new SqliAnalyser() ;
				$sql_analyser->analyse($saniArr, $encodingArr) ;
				break ;
			case 'XSS':
				break ;
			case 'HTTP':
				
				break ;
			case 'CODE':
				
				break ;
			case 'EXEC':
				break ;
			case 'LDAP':
				break ;
			case 'INCLUDE':
				break ;
			case 'FILE':
				break ;
			case 'XPATH':
				break ;
			case 'FILEAFFECT':
				break ;
		}
	}
	
	
	/**
	 * 污点分析的函数
	 * @param BasicBlock $block 当前基本块
	 * @param Node $node 当前的函数调用node
	 * @param string $argName 危险参数名
	 */
	public function analysis($block,$node,$argName){
		
		//获取前驱基本块集合并将当前基本量添加至列表
		$this->getPrevBlocks($block) ;
		$block_list = $this->pathArr ;
		array_push($block_list, $block) ;
		//首先，在当前基本块中探测变量，如果有source和不完整的santi则报告漏洞
		$ret = $this->currBlockTaintHandler($block, $node, $argName) ;
		if($ret === true) return ;
		
		//多个基本块的处理
		$this->pathArr = array() ;
		$this->multiBlockHandler($block, $argName, $node) ;
		
		return array() ;
	}
	
	
	/**
	 * 报告漏洞的函数
	 * @param Node $node 出现漏洞的node
	 * @param Node $var  出现漏洞的变量node
	 */
	public function report($node,$var){
		echo "<pre>" ;
		echo "有漏洞！！！！<br/>" ;
		echo "漏洞变量：<br/>" ;
		print_r($var) ;
		echo "漏洞节点：<br/>" ;
		print_r($node) ;
	}
	
	
}
?>