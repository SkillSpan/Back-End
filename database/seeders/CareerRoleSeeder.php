<?php

namespace Database\Seeders;

use App\Models\CareerRole;
use App\Models\Skill;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Career roles and their required skills.
 *
 * One row per role, keyed by a stable slug, so a role shared by several
 * specializations (Backend Developer belongs to Computer Science, Software
 * Engineering, Web Development, …) is a SINGLE career_roles row linked many
 * times through the pivot — never duplicated per specialization.
 *
 * Entry format: slug => [title, "skill, skill, …"]. Skills are resolved by
 * NAME first (so "C#", whose slug would collide with "C++", still resolves)
 * and then by slug. The first skill is treated as the critical one.
 *
 * The required skills are converged on every run: a role that previously
 * carried a skill this seeder no longer lists has it removed, so an old
 * definition cannot leave a skill with no mapped question (which would break
 * the assessment for the whole role).
 */
class CareerRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->roles() as $slug => [$title, $skillList]) {
            $careerRole = CareerRole::updateOrCreate(
                ['slug' => $slug, 'version' => 1],
                [
                    'title' => $title,
                    'status' => 'approved',
                    'effective_date' => now()->toDateString(),
                ]
            );

            $resolvedSkillIds = [];

            foreach ($this->splitSkills($skillList) as $index => $skillName) {
                $skill = $this->resolveSkill($skillName);

                if (! $skill) {
                    continue; // Skip silently if SkillSeeder hasn't created it yet.
                }

                $resolvedSkillIds[] = $skill->id;

                $careerRole->roleSkills()->updateOrCreate(
                    ['skill_id' => $skill->id],
                    [
                        'required_level' => $index === 0 ? 3.0 : 2.5,
                        'importance_weight' => round(max(0.4, 0.9 - ($index * 0.1)), 3),
                        'is_critical' => $index === 0,
                    ]
                );
            }

            if ($resolvedSkillIds !== []) {
                $careerRole->roleSkills()
                    ->whereNotIn('skill_id', $resolvedSkillIds)
                    ->delete();
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function splitSkills(string $skillList): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $skillList))));
    }

    private function resolveSkill(string $name): ?Skill
    {
        return Skill::where('name', $name)->first()
            ?? Skill::where('slug', Str::slug($name))->first();
    }

    /**
     * slug => [title, "skill, skill, …"]
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function roles(): array
    {
        return [
            // ---- Core software engineering ----
            'software-engineer' => ['Software Engineer', 'Programming Fundamentals,Data Structures,Algorithms,Software Design,Version Control (Git)'],
            'software-developer' => ['Software Developer', 'Programming Fundamentals,Data Structures,Object-Oriented Programming,Debugging,Version Control (Git)'],
            'software-architect' => ['Software Architect', 'Software Design,Backend Architecture,Database Design,Cloud Computing Fundamentals,Security Architecture'],
            'full-stack-developer' => ['Full Stack Developer', 'Frontend Frameworks,Backend Architecture,REST APIs,SQL & Databases,Version Control (Git)'],
            'backend-developer' => ['Backend Developer', 'Programming Fundamentals,SQL & Databases,REST APIs,Authentication & Authorization,Backend Architecture'],
            'frontend-developer' => ['Frontend Developer', 'HTML,CSS,JavaScript,Responsive Design,Frontend Frameworks'],
            'mobile-developer' => ['Mobile Developer', 'Android Development,Application Lifecycle,UI Components,REST API Integration,Local Data Storage'],
            'qa-engineer' => ['QA Engineer', 'Manual Testing,Test Case Design,Bug Reporting,Test Automation,API Testing'],
            'test-automation-engineer' => ['Test Automation Engineer', 'Test Automation,Selenium,API Testing,CI/CD,Version Control (Git)'],
            'devops-engineer' => ['DevOps Engineer', 'CI/CD,Docker,Kubernetes,Linux Administration,Cloud Computing Fundamentals'],

            // ---- Information Technology ----
            'it-support-specialist' => ['IT Support Specialist', 'Computer Hardware,Operating Systems,Networking Fundamentals,Troubleshooting,Internet Fundamentals'],
            'system-administrator' => ['System Administrator', 'Operating Systems,Servers,User & Permission Management,Backup & Recovery,System Monitoring'],
            'systems-administrator' => ['Systems Administrator', 'Windows Server,Active Directory,User & Permission Management,Virtualization,System Monitoring'],
            'network-administrator' => ['Network Administrator', 'TCP/IP,IP Addressing,Routing & Switching,Network Security,Network Troubleshooting'],
            'it-administrator' => ['IT Administrator', 'Windows Server,Active Directory,IT Service Management,User & Permission Management,Troubleshooting'],
            'cloud-support-engineer' => ['Cloud Support Engineer', 'Cloud Computing Fundamentals,AWS,Microsoft Azure,Troubleshooting,Networking Fundamentals'],
            'technical-support-engineer' => ['Technical Support Engineer', 'Help Desk Operations,Troubleshooting,Operating Systems,Networking Fundamentals,IT Service Management'],
            'it-operations-specialist' => ['IT Operations Specialist', 'IT Service Management,System Monitoring,Linux Administration,Backup & Recovery,Virtualization'],

            // ---- Information Systems ----
            'business-analyst' => ['Business Analyst', 'Requirements Analysis,Stakeholder Analysis,Business Requirements,Use Cases,Process Analysis'],
            'systems-analyst' => ['Systems Analyst', 'System Analysis,Requirements Engineering,UML,Data Flow,System Documentation'],
            'it-business-analyst' => ['IT Business Analyst', 'Requirements Analysis,Business Requirements,Process Analysis,IT Project Management,ERP Systems'],
            'erp-specialist' => ['ERP Specialist', 'ERP Systems,Business Requirements,Process Analysis,Database Design,User & Permission Management'],
            'crm-specialist' => ['CRM Specialist', 'CRM Systems,Business Requirements,Data Analysis,Process Automation,Stakeholder Analysis'],
            'database-administrator' => ['Database Administrator', 'Database Design,Query Optimization,Database Indexing,Backup & Recovery,SQL & Databases'],

            // ---- IT Management ----
            'it-manager' => ['IT Manager', 'IT Governance,IT Project Management,IT Service Management,Change Management,Technology Consulting'],
            'it-project-coordinator' => ['IT Project Coordinator', 'IT Project Management,Requirements Analysis,Stakeholder Analysis,Process Analysis,Change Management'],
            'technology-consultant' => ['Technology Consultant', 'Technology Consulting,Requirements Analysis,Business Requirements,Cloud Computing Fundamentals,Stakeholder Analysis'],
            'it-operations-manager' => ['IT Operations Manager', 'IT Service Management,IT Governance,System Monitoring,Change Management,Virtualization'],
            'digital-transformation-specialist' => ['Digital Transformation Specialist', 'Digital Transformation,Process Automation,Change Management,Business Requirements,Technology Consulting'],

            // ---- Computer Engineering ----
            'embedded-systems-developer' => ['Embedded Systems Developer', 'Embedded Systems,Microcontrollers,GPIO,C/C++,Memory Management'],
            'embedded-software-engineer' => ['Embedded Software Engineer', 'Embedded C,RTOS,Microcontrollers,Memory Management,Debugging'],
            'firmware-engineer' => ['Firmware Engineer', 'Firmware Development,Embedded C,RTOS,Microcontrollers,Debugging'],
            'iot-developer' => ['IoT Developer', 'IoT Fundamentals,Sensors & Actuators,MQTT,Device Communication,IoT Security'],
            'iot-engineer' => ['IoT Engineer', 'IoT Fundamentals,Device Communication,MQTT,Signal Processing,IoT Security'],
            'robotics-engineer' => ['Robotics Engineer', 'Robotics,ROS,Control Systems,Embedded Systems,C/C++'],
            'hardware-software-integration-engineer' => ['Hardware-Software Integration Engineer', 'Hardware-Software Integration,Embedded Systems,PCB Design,Microcontrollers,Debugging'],

            // ---- Web Development ----
            'wordpress-developer' => ['WordPress Developer', 'WordPress,PHP,HTML,CSS,JavaScript'],
            'php-developer' => ['PHP Developer', 'PHP,Laravel,SQL & Databases,REST APIs,Authentication & Authorization'],
            'laravel-developer' => ['Laravel Developer', 'Laravel,PHP,SQL & Databases,REST APIs,Authentication & Authorization'],
            'node-js-developer' => ['Node.js Developer', 'Node.js,JavaScript,REST APIs,SQL & Databases,Authentication & Authorization'],
            'react-developer' => ['React Developer', 'React,JavaScript,HTML,CSS,Frontend Frameworks'],
            'next-js-developer' => ['Next.js Developer', 'Next.js,React,JavaScript,TypeScript,Frontend Frameworks'],
            'web-application-developer' => ['Web Application Developer', 'Web Application Development,JavaScript,REST APIs,SQL & Databases,HTML'],

            // ---- Mobile ----
            'android-developer' => ['Android Developer', 'Android Development,Kotlin,Application Lifecycle,UI Components,REST API Integration'],
            'ios-developer' => ['iOS Developer', 'iOS Development,Swift,Application Lifecycle,UI Components,REST API Integration'],
            'flutter-developer' => ['Flutter Developer', 'Flutter,Dart,Cross-Platform Development,UI Components,REST API Integration'],
            'react-native-developer' => ['React Native Developer', 'React Native,JavaScript,Cross-Platform Development,UI Components,REST API Integration'],
            'mobile-application-developer' => ['Mobile Application Developer', 'Android Development,Application Lifecycle,UI Components,REST API Integration,Local Data Storage'],
            'cross-platform-mobile-developer' => ['Cross-Platform Mobile Developer', 'Cross-Platform Development,Flutter,React Native,UI Components,REST API Integration'],

            // ---- Data ----
            'data-analyst' => ['Data Analyst', 'Statistics Fundamentals,SQL,Data Cleaning,Data Visualization,Data Analysis'],
            'data-scientist' => ['Data Scientist', 'Statistics,Python,Machine Learning,Feature Engineering,Model Evaluation'],
            'machine-learning-engineer' => ['Machine Learning Engineer', 'Machine Learning,Python,Model Training,Model Deployment,Model Monitoring'],
            'data-engineer' => ['Data Engineer', 'ETL,Data Warehousing,SQL & Databases,Data Pipelines,Apache Spark'],
            'business-intelligence-analyst' => ['Business Intelligence Analyst', 'Business Intelligence,Power BI,SQL,Data Visualization,KPIs and Metrics'],
            'analytics-engineer' => ['Analytics Engineer', 'Data Modeling,SQL & Databases,ETL,Data Warehousing,Data Pipelines'],
            'bi-developer' => ['BI Developer', 'Power BI,Tableau,SQL & Databases,Data Modeling,Data Visualization'],
            'product-data-analyst' => ['Product Data Analyst', 'Data Analysis,SQL,KPIs and Metrics,Data Visualization,Statistics Fundamentals'],
            'marketing-data-analyst' => ['Marketing Data Analyst', 'Data Analysis,Data Visualization,KPIs and Metrics,SQL,Statistics Fundamentals'],
            'data-visualization-specialist' => ['Data Visualization Specialist', 'Data Visualization,Tableau,Power BI,Data Analysis,UI Design'],
            'big-data-engineer' => ['Big Data Engineer', 'Big Data,Apache Spark,Data Pipelines,ETL,Data Warehousing'],
            'etl-developer' => ['ETL Developer', 'ETL,Data Pipelines,SQL & Databases,Data Warehousing,Data Modeling'],
            'data-platform-engineer' => ['Data Platform Engineer', 'Data Pipelines,Cloud Computing Fundamentals,Data Warehousing,Docker,SQL & Databases'],
            'database-engineer' => ['Database Engineer', 'Database Design,Query Optimization,Database Indexing,SQL & Databases,NoSQL'],

            // ---- Artificial Intelligence ----
            'ai-engineer' => ['AI Engineer', 'Python,Machine Learning,Neural Networks,Model Evaluation,AI Fundamentals'],
            'deep-learning-engineer' => ['Deep Learning Engineer', 'Deep Learning,Neural Networks,TensorFlow,PyTorch,Model Evaluation'],
            'ai-developer' => ['AI Developer', 'AI Fundamentals,Python,Machine Learning,REST APIs,Model Deployment'],
            'computer-vision-engineer' => ['Computer Vision Engineer', 'Computer Vision,OpenCV,Deep Learning,Image Processing,Python'],
            'nlp-engineer' => ['NLP Engineer', 'Natural Language Processing,Tokenization,Word Embeddings,TF-IDF,Text Classification'],
            'generative-ai-engineer' => ['Generative AI Engineer', 'Generative AI,Large Language Models,Prompt Engineering,Retrieval-Augmented Generation,Python'],
            'ai-solutions-engineer' => ['AI Solutions Engineer', 'AI Fundamentals,Machine Learning,Model Deployment,Cloud Computing Fundamentals,Python'],
            'ml-developer' => ['ML Developer', 'Machine Learning,Python,Model Training,Feature Engineering,Model Deployment'],
            'applied-machine-learning-engineer' => ['Applied Machine Learning Engineer', 'Machine Learning,Feature Engineering,Model Evaluation,Python,Model Deployment'],
            'mlops-engineer' => ['MLOps Engineer', 'MLOps,Model Deployment,Model Monitoring,Docker,CI/CD'],
            'nlp-developer' => ['NLP Developer', 'Natural Language Processing,Text Classification,Python,Tokenization,Word Embeddings'],
            'conversational-ai-engineer' => ['Conversational AI Engineer', 'Natural Language Processing,Large Language Models,Prompt Engineering,Text Classification,Python'],
            'language-ai-engineer' => ['Language AI Engineer', 'Natural Language Processing,Large Language Models,Word Embeddings,Python,Model Evaluation'],
            'ai-research-assistant' => ['AI Research Assistant', 'AI Fundamentals,Machine Learning,Statistics,Python,Model Evaluation'],
            'computer-vision-developer' => ['Computer Vision Developer', 'Computer Vision,OpenCV,Python,Image Processing,Deep Learning'],
            'image-processing-engineer' => ['Image Processing Engineer', 'Image Processing,Computer Vision,OpenCV,Signal Processing,Python'],
            'ai-vision-engineer' => ['AI Vision Engineer', 'Computer Vision,Deep Learning,OpenCV,Python,Model Deployment'],
            'llm-engineer' => ['LLM Engineer', 'Large Language Models,Prompt Engineering,Retrieval-Augmented Generation,Python,Model Deployment'],
            'ai-application-developer' => ['AI Application Developer', 'AI Fundamentals,REST APIs,Prompt Engineering,Python,Model Deployment'],
            'prompt-engineer' => ['Prompt Engineer', 'Prompt Engineering,Large Language Models,Generative AI,Natural Language Processing,Model Evaluation'],
            'rag-engineer' => ['RAG Engineer', 'Retrieval-Augmented Generation,Large Language Models,Prompt Engineering,Python,Data Pipelines'],
            'ai-automation-engineer' => ['AI Automation Engineer', 'Process Automation,AI Fundamentals,Python,REST APIs,Model Deployment'],
            'robotics-software-engineer' => ['Robotics Software Engineer', 'Robotics,ROS,C/C++,Control Systems,Debugging'],
            'autonomous-systems-engineer' => ['Autonomous Systems Engineer', 'Robotics,Control Systems,Computer Vision,ROS,Algorithms'],
            'ai-robotics-engineer' => ['AI Robotics Engineer', 'Robotics,ROS,Deep Learning,Control Systems,Python'],
            'embedded-ai-engineer' => ['Embedded AI Engineer', 'Embedded Systems,Deep Learning,TensorFlow,Microcontrollers,Python'],

            // ---- Cybersecurity ----
            'security-analyst' => ['Security Analyst', 'Cybersecurity Fundamentals,Threats & Vulnerabilities,Authentication,Encryption,Network Security'],
            'soc-analyst' => ['SOC Analyst', 'SIEM,Log Analysis,Security Monitoring,Incident Response,Security Events'],
            'cybersecurity-engineer' => ['Cybersecurity Engineer', 'Security Architecture,Network Security,Secure Coding,Identity and Access Management,Encryption'],
            'security-engineer' => ['Security Engineer', 'Security Architecture,Secure Coding,Encryption,Identity and Access Management,Network Security'],
            'incident-response-analyst' => ['Incident Response Analyst', 'Incident Response,Incident Handling,Log Analysis,SIEM,Security Events'],
            'threat-intelligence-analyst' => ['Threat Intelligence Analyst', 'Threats & Vulnerabilities,Security Monitoring,Log Analysis,Malware Analysis,SIEM'],
            'security-consultant' => ['Security Consultant', 'Cybersecurity Fundamentals,Security Architecture,Threats & Vulnerabilities,Identity and Access Management,Technology Consulting'],
            'penetration-tester' => ['Penetration Tester', 'Vulnerability Assessment,Penetration Testing,Web Security,SQL Injection,Threat Modeling'],
            'ethical-hacker' => ['Ethical Hacker', 'Ethical Hacking,Penetration Testing,Web Security,SQL Injection,Vulnerability Assessment'],
            'red-team-operator' => ['Red Team Operator', 'Red Teaming,Ethical Hacking,Exploit Development,Penetration Testing,Web Security'],
            'vulnerability-analyst' => ['Vulnerability Analyst', 'Vulnerability Assessment,Threats & Vulnerabilities,Security Monitoring,Penetration Testing,Log Analysis'],
            'security-testing-engineer' => ['Security Testing Engineer', 'Penetration Testing,Test Automation,Web Security,Vulnerability Assessment,SQL Injection'],
            'digital-forensics-analyst' => ['Digital Forensics Analyst', 'Digital Forensics,Incident Handling,Evidence Collection,Malware Analysis,Log Analysis'],
            'dfir-analyst' => ['DFIR Analyst', 'Digital Forensics,Incident Response,Incident Handling,Malware Analysis,Log Analysis'],
            'cybercrime-analyst' => ['Cybercrime Analyst', 'Digital Forensics,Threats & Vulnerabilities,Malware Analysis,Log Analysis,Security Monitoring'],
            'incident-response-specialist' => ['Incident Response Specialist', 'Incident Response,Incident Handling,Digital Forensics,SIEM,Security Events'],

            // ---- Computer Networks ----
            'network-engineer' => ['Network Engineer', 'TCP/IP,IP Addressing,Routing & Switching,VLANs,Network Design'],
            'network-support-engineer' => ['Network Support Engineer', 'Networking Fundamentals,TCP/IP,IP Addressing,Network Troubleshooting,Troubleshooting'],
            'network-security-engineer' => ['Network Security Engineer', 'Network Security,Routing & Switching,Encryption,Network Troubleshooting,VLANs'],
            'noc-engineer' => ['NOC Engineer', 'Network Troubleshooting,System Monitoring,TCP/IP,Routing & Switching,Log Analysis'],

            // ---- Cloud ----
            'cloud-engineer' => ['Cloud Engineer', 'Cloud Computing Fundamentals,AWS,Microsoft Azure,Docker,Linux Administration'],
            'cloud-administrator' => ['Cloud Administrator', 'Cloud Computing Fundamentals,AWS,Microsoft Azure,Virtualization,Linux Administration'],
            'cloud-architect' => ['Cloud Architect', 'Cloud Computing Fundamentals,AWS,Microsoft Azure,Infrastructure as Code,Kubernetes'],
            'cloud-security-engineer' => ['Cloud Security Engineer', 'Cloud Computing Fundamentals,Security Architecture,Encryption,Identity and Access Management,Network Security'],

            // ---- DevOps & Infrastructure ----
            'site-reliability-engineer' => ['Site Reliability Engineer', 'Kubernetes,Observability,CI/CD,Linux Administration,Docker'],
            'platform-engineer' => ['Platform Engineer', 'Kubernetes,Infrastructure as Code,Terraform,Docker,CI/CD'],
            'infrastructure-engineer' => ['Infrastructure Engineer', 'Infrastructure as Code,Terraform,Linux Administration,Virtualization,Cloud Computing Fundamentals'],
            'devsecops-engineer' => ['DevSecOps Engineer', 'CI/CD,Security Architecture,Docker,Kubernetes,Secure Coding'],
            'cicd-engineer' => ['CI/CD Engineer', 'CI/CD,Docker,Kubernetes,Version Control (Git),Linux Administration'],

            // ---- Databases ----
            'sql-developer' => ['SQL Developer', 'SQL & Databases,Database Design,Query Optimization,Database Indexing,Data Modeling'],
            'database-developer' => ['Database Developer', 'Database Design,SQL & Databases,Query Optimization,NoSQL,Data Modeling'],

            // ---- UI/UX ----
            'ui-designer' => ['UI Designer', 'UI Design,Figma,Design Systems,Prototyping,Accessibility'],
            'ux-designer' => ['UX Designer', 'UX Research,Wireframing,Prototyping,Interaction Design,Accessibility'],
            'product-designer' => ['Product Designer', 'UI Design,UX Research,Prototyping,Design Systems,Interaction Design'],
            'ux-researcher' => ['UX Researcher', 'UX Research,Wireframing,Accessibility,Data Analysis,Interaction Design'],
            'interaction-designer' => ['Interaction Designer', 'Interaction Design,Prototyping,UI Design,UX Research,Figma'],
            'design-system-designer' => ['Design System Designer', 'Design Systems,UI Design,Figma,Accessibility,Frontend Frameworks'],

            // ---- Game Development ----
            'game-developer' => ['Game Developer', 'Game Design,Gameplay Programming,C#,Unity,3D Mathematics'],
            'unity-developer' => ['Unity Developer', 'Unity,C#,Gameplay Programming,3D Mathematics,Game Design'],
            'unreal-engine-developer' => ['Unreal Engine Developer', 'Unreal Engine,C/C++,Gameplay Programming,3D Mathematics,Game Design'],
            'gameplay-programmer' => ['Gameplay Programmer', 'Gameplay Programming,C#,Unity,3D Mathematics,Algorithms'],
            'game-programmer' => ['Game Programmer', 'Gameplay Programming,C#,C/C++,Algorithms,3D Mathematics'],
            'game-designer' => ['Game Designer', 'Game Design,3D Mathematics,UX Research,Prototyping,UI Design'],
            'technical-game-designer' => ['Technical Game Designer', 'Game Design,Gameplay Programming,3D Mathematics,C#,Prototyping'],

            // ---- Quality Assurance ----
            'software-tester' => ['Software Tester', 'Manual Testing,Test Case Design,Bug Reporting,API Testing,Troubleshooting'],
            'manual-qa-tester' => ['Manual QA Tester', 'Manual Testing,Test Case Design,Bug Reporting,API Testing,System Documentation'],
            'automation-qa-engineer' => ['Automation QA Engineer', 'Test Automation,Selenium,API Testing,CI/CD,Version Control (Git)'],
            'performance-test-engineer' => ['Performance Test Engineer', 'Performance Testing,Test Automation,API Testing,Linux Administration,Observability'],

            // ---- Blockchain & Web3 ----
            'blockchain-developer' => ['Blockchain Developer', 'Blockchain Fundamentals,Solidity,Smart Contracts,Web3 Development,Cryptography'],
            'smart-contract-developer' => ['Smart Contract Developer', 'Solidity,Smart Contracts,Ethereum,Cryptography,Blockchain Fundamentals'],
            'web3-developer' => ['Web3 Developer', 'Web3 Development,Blockchain Fundamentals,Solidity,JavaScript,REST APIs'],
            'blockchain-engineer' => ['Blockchain Engineer', 'Blockchain Fundamentals,Cryptography,Solidity,Smart Contracts,Web3 Development'],
            'solidity-developer' => ['Solidity Developer', 'Solidity,Smart Contracts,Ethereum,Cryptography,Blockchain Fundamentals'],
            'web3-backend-developer' => ['Web3 Backend Developer', 'Web3 Development,REST APIs,Blockchain Fundamentals,Solidity,SQL & Databases'],

            // ---- Digital Transformation ----
            'business-systems-analyst' => ['Business Systems Analyst', 'System Analysis,Business Requirements,Requirements Analysis,UML,Process Automation'],
            'automation-specialist' => ['Automation Specialist', 'Process Automation,Python,REST APIs,Data Pipelines,Troubleshooting'],
            'digital-solutions-specialist' => ['Digital Solutions Specialist', 'Digital Transformation,Business Requirements,Cloud Computing Fundamentals,REST APIs,Process Automation'],
        ];
    }
}
